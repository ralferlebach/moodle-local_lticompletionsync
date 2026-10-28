<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_lticompletionsync;

use local_lticompletionsync\local\merge_integration;
use local_lticompletionsync\local\receiver\processor;
use local_lticompletionsync\local\sender\outbox;
use local_lticompletionsync\tests\loopback_http_client;
use local_lticompletionsync\tests\sync_env;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/sync_env.php');
require_once(__DIR__ . '/fixtures/loopback_http_client.php');
require_once(__DIR__ . '/fixtures/settings_hook_standin.php');
require_once(__DIR__ . '/fixtures/after_merge_hook_standin.php');

/**
 * Account merges with tool_mergeusers: outbox, receiver states and journal follow the kept user without duplicates.
 *
 * tool_mergeusers is not installed on the test sites (and must not be). Its hooks are represented by stand-ins with
 * exactly the public API the callbacks use (as in the sibling plugins); the hook registration is checked against
 * core's hook manager; with the real hook classes present the settings callback also runs against them.
 *
 * @package    local_lticompletionsync
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_lticompletionsync\local\merge_integration
 * @covers     \local_lticompletionsync\hook_callbacks
 */
final class merge_integration_test extends \advanced_testcase {
    use sync_env;

    /**
     * The settings take all three user tables out of the generic processing (string keys) and declare the unique
     * indexes exactly as install.xml defines them; tool_mergeusers' defaults are not replaced.
     */
    public function test_settings(): void {
        global $DB;
        $settings = merge_integration::db_settings();
        $tables = ['local_lticompletionsync_out', 'local_lticompletionsync_rcv', 'local_lticompletionsync_log'];
        $this->assertSame(array_combine($tables, $tables), $settings['exceptions']);
        $schema = $DB->get_manager()->get_install_xml_schema();
        foreach (['local_lticompletionsync_out', 'local_lticompletionsync_rcv'] as $table) {
            $index = array_values(array_filter($schema->getTable($table)->getIndexes(), fn($i) => $i->getName() === 'target'))[0];
            $this->assertTrue($index->getUnique());
            $declared = $settings['compoundindexes'][$table];
            $this->assertEqualsCanonicalizing($index->getFields(), array_merge($declared['userfield'], $declared['otherfields']));
        }

        $defaults = ['exceptions' => ['user_preferences', 'user_private_key', 'user_info_data', 'my_pages']];
        $layered = new tests\settings_hook_standin();
        hook_callbacks::mergeusers_add_settings($layered);
        $layered->add_raw_settings($defaults);
        $skip = array_flip($layered->get_raw()['exceptions']);
        foreach (array_merge($defaults['exceptions'], $tables) as $t) {
            $this->assertArrayHasKey($t, $skip, $t);
        }

        $class = 'tool_mergeusers\\hook\\add_settings_before_merging';
        if (class_exists($class)) {
            $hook = new $class();
            hook_callbacks::mergeusers_add_settings($hook);
            $this->assertContains('local_lticompletionsync_out', $hook->get_settings()->exceptions);
        }
    }

    /**
     * db/hooks.php registers both callbacks for the tool_mergeusers hooks (dispatched only when it is installed).
     */
    public function test_hooks_registered(): void {
        $manager = \core\di::get(\core\hook\manager::class);
        foreach (
            [
                'tool_mergeusers\\hook\\add_settings_before_merging' => 'mergeusers_add_settings',
                'tool_mergeusers\\hook\\after_merged_all_tables' => 'mergeusers_after_merged_all_tables',
            ] as $hook => $method
        ) {
            $callbacks = $manager->get_callbacks_for_hook($hook);
            $found = array_filter($callbacks, fn($c) => ($c['component'] ?? '') === 'local_lticompletionsync');
            $this->assertCount(1, $found, $hook);
            $this->assertStringContainsString($method, json_encode(array_values($found)[0]['callback']));
        }
    }

    /**
     * Tool side and platform side of a real loopback flow with two accounts on each side; merging moves the rows of
     * the merged-away account, keeps exactly one row per target (the newer one) and never moves versions backwards.
     */
    public function test_merge_moves_rows_without_duplicates(): void {
        global $DB;
        $this->resetAfterTest();
        $this->build_env();
        $http = new loopback_http_client();
        $second = $this->getDataGenerator()->create_and_enrol($this->pcourse, 'student');

        // Two platform learners launch and complete; the tool provisions two tool accounts.
        $tool1 = $this->launch($this->plearner, $this->tresource);
        $tool2 = $this->launch($second, $this->tresource);
        $this->tool_complete($tool1, true);
        $this->tool_complete($tool2, true);
        $this->tool_complete($tool2, false);
        $this->tool_complete($tool2, true);
        $this->deliver_all($http);
        $out1 = $this->outrow($tool1);
        $out2 = $this->outrow($tool2);
        $this->assertSame(1, (int) $out1->version);
        $this->assertSame(3, (int) $out2->version);
        $this->assertSame(2, $DB->count_records(processor::TABLE));

        // Tool side merge: tool2 (newer version) is merged into tool1 (kept). Same target: the newer row survives.
        $hook = new tests\after_merge_hook_standin($tool1, $tool2);
        hook_callbacks::mergeusers_after_merged_all_tables($hook);
        $this->assertSame([], $hook->errors);
        $rows = $DB->get_records(outbox::TABLE, ['resourcelinkid' => $out1->resourcelinkid]);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame($tool1, (int) $row->userid);
        $this->assertSame(3, (int) $row->version);
        $this->assertSame($out2->correlationid, $row->correlationid);
        $this->assertSame(0, $DB->count_records(outbox::TABLE, ['userid' => $tool2]));
        $this->assertSame(0, $DB->count_records('local_lticompletionsync_log', ['userid' => $tool2]));
        $this->assertStringContainsString('joined 1', $hook->logs[0]);

        // Platform side merge: second learner into the first; one receiver state per target remains.
        $hook = new tests\after_merge_hook_standin((int) $this->plearner->id, (int) $second->id);
        hook_callbacks::mergeusers_after_merged_all_tables($hook);
        $this->assertSame([], $hook->errors);
        $this->assertSame(1, $DB->count_records(processor::TABLE, ['cmid' => $this->pcm->id]));
        $this->assertSame(0, $DB->count_records(processor::TABLE, ['userid' => $second->id]));

        // A second run changes nothing (idempotent).
        $hook = new tests\after_merge_hook_standin((int) $this->plearner->id, (int) $second->id);
        hook_callbacks::mergeusers_after_merged_all_tables($hook);
        $this->assertSame(1, $DB->count_records(processor::TABLE, ['cmid' => $this->pcm->id]));
    }

    /**
     * After a platform-side merge, a message of the other subject's version sequence is ordered by the provider time
     * stamp: older states are refused as superseded (acknowledged by the sender), newer ones apply.
     */
    public function test_joined_sequences_are_ordered_by_provider_time(): void {
        global $DB;
        $this->resetAfterTest();
        $this->build_env();
        $receiver = new processor();
        $base = ['resource_link_id' => (string) $this->pcm->instance, 'state' => 'completed',
            'correlation_id' => \core\uuid::generate()];
        $now = time();
        $r = $receiver->completion($this->receiver, $base + ['user' => (string) $this->plearner->id, 'version' => 5,
            'timestamp' => $now]);
        $this->assertSame('applied', $r['status']);
        // Pretend the row was joined from another subject: its hash differs from the next message's subject.
        $DB->set_field(processor::TABLE, 'subjecthash', hash('sha256', 'merged-away-subject'), ['cmid' => $this->pcm->id]);

        $r = $receiver->completion($this->receiver, ['state' => 'incomplete', 'user' => (string) $this->plearner->id,
            'version' => 1, 'timestamp' => $now - 100] + $base);
        $this->assertSame('rejected', $r['status']);
        $this->assertSame('superseded', $r['reason']);
        $this->assertSame(1, $this->platform_state());

        $r = $receiver->completion($this->receiver, ['state' => 'incomplete', 'user' => (string) $this->plearner->id,
            'version' => 1, 'timestamp' => $now + 10] + $base);
        $this->assertSame('applied', $r['status']);
        $this->assertSame(0, $this->platform_state());
        $this->assertSame(
            hash('sha256', (string) $this->plearner->id),
            $DB->get_field(processor::TABLE, 'subjecthash', ['cmid' => $this->pcm->id])
        );
    }

    /**
     * A failure inside the merge is reported as an error (tool_mergeusers then rolls the whole merge back).
     */
    public function test_failure_is_reported(): void {
        $hook = new tests\after_merge_hook_standin(5, 5);
        merge_integration::after_merged_all_tables($hook);
        $this->assertCount(1, $hook->errors);
        $this->assertSame([], $hook->logs);
    }
}
