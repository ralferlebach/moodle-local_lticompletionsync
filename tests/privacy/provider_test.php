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

namespace local_lticompletionsync\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_lticompletionsync\tests\loopback_http_client;
use local_lticompletionsync\tests\sync_env;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/sync_env.php');
require_once(__DIR__ . '/../fixtures/loopback_http_client.php');

/**
 * Privacy provider on real data of both roles.
 *
 * @package    local_lticompletionsync
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_lticompletionsync\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    use sync_env;

    /** @var int */
    private $tooluser;

    /**
     * Run a full transfer so that outbox, received state and journal hold data.
     */
    private function transfer(): void {
        $this->build_env();
        $this->tooluser = $this->launch($this->plearner, $this->tresource);
        $this->tool_complete($this->tooluser, true);
        $this->deliver_all(new loopback_http_client());
    }

    /**
     * Metadata.
     */
    public function test_metadata(): void {
        $collection = provider::get_metadata(new \core_privacy\local\metadata\collection('local_lticompletionsync'));
        $this->assertCount(4, $collection->get_collection());
    }

    /**
     * Contexts, users, export and deletion.
     */
    public function test_contexts_export_delete(): void {
        global $DB;
        $this->resetAfterTest();
        $this->transfer();
        $pctx = \context_module::instance($this->pcm->id);
        $tctx = \context_module::instance($this->tpage->cmid);

        $platformcontexts = provider::get_contexts_for_userid((int) $this->plearner->id)->get_contextids();
        $this->assertContains((string) $pctx->id, array_map('strval', $platformcontexts));
        $toolcontexts = provider::get_contexts_for_userid($this->tooluser)->get_contextids();
        $this->assertContains((string) $tctx->id, array_map('strval', $toolcontexts));

        $userlist = new userlist($pctx, 'local_lticompletionsync');
        provider::get_users_in_context($userlist);
        $this->assertContains((int) $this->plearner->id, $userlist->get_userids());

        $this->export_context_data_for_user((int) $this->plearner->id, $pctx, 'local_lticompletionsync');
        $data = writer::with_context($pctx)->get_data([get_string('pluginname', 'local_lticompletionsync')]);
        $this->assertNotEmpty($data->local_lticompletionsync_rcv);

        provider::delete_data_for_user(new approved_contextlist(
            \core_user::get_user($this->tooluser),
            'local_lticompletionsync',
            [$tctx->id]
        ));
        $this->assertFalse($DB->record_exists('local_lticompletionsync_out', ['userid' => $this->tooluser]));

        provider::delete_data_for_users(new approved_userlist(
            $pctx,
            'local_lticompletionsync',
            [(int) $this->plearner->id]
        ));
        $this->assertFalse($DB->record_exists('local_lticompletionsync_rcv', ['userid' => $this->plearner->id]));

        provider::delete_data_for_all_users_in_context($pctx);
        $this->assertFalse($DB->record_exists('local_lticompletionsync_log', ['cmid' => $this->pcm->id]));
        // Native completion of the platform is core data and stays.
        $this->assertSame(1, $this->platform_state());
    }

    /**
     * A deleted user leaves no state behind.
     */
    public function test_user_deleted_observer(): void {
        global $DB;
        $this->resetAfterTest();
        $this->transfer();
        delete_user(\core_user::get_user($this->tooluser));
        delete_user(\core_user::get_user((int) $this->plearner->id));
        $this->assertFalse($DB->record_exists('local_lticompletionsync_out', ['userid' => $this->tooluser]));
        $this->assertFalse($DB->record_exists('local_lticompletionsync_rcv', ['userid' => $this->plearner->id]));
        $this->assertFalse($DB->record_exists('local_lticompletionsync_log', ['userid' => $this->plearner->id]));
    }
}
