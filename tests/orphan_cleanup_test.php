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

use local_lticompletionsync\local\connection;
use local_lticompletionsync\local\orphan_cleanup;
use local_lticompletionsync\tests\loopback_http_client;
use local_lticompletionsync\tests\sync_env;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/sync_env.php');
require_once(__DIR__ . '/fixtures/loopback_http_client.php');

/**
 * Data retention: rows whose course, activity, tool type, published tool, deployment or user was deleted are removed
 * (events where core has them, bounded sweep and upgrade step where it has none).
 *
 * @package    local_lticompletionsync
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_lticompletionsync\local\orphan_cleanup
 * @covers     \local_lticompletionsync\observer
 */
final class orphan_cleanup_test extends \advanced_testcase {
    use sync_env;

    /**
     * Both roles with rows: a completion delivered through the loopback (outbox, receiver state, activity, journal).
     *
     * @return int tool user id
     */
    private function flow(): int {
        global $DB;
        $this->build_env();
        $tooluser = $this->launch($this->plearner, $this->tresource);
        $this->tool_complete($tooluser, true);
        $this->deliver_all(new loopback_http_client());
        $this->assertSame(1, $DB->count_records('local_lticompletionsync_rcv'));
        $this->assertSame(1, $DB->count_records('local_lticompletionsync_act'));
        $this->assertGreaterThan(0, $DB->count_records('local_lticompletionsync_out'));
        return $tooluser;
    }

    /**
     * Deleting the platform course removes the receiver rows and the course journal; deleting the tool course removes
     * the outbox rows (real delete_course(), which triggers no course_module_deleted for the modules).
     */
    public function test_course_deletion_removes_rows(): void {
        global $DB;
        $this->resetAfterTest();
        $this->flow();
        $this->assertGreaterThan(0, $DB->count_records('local_lticompletionsync_log', ['courseid' => $this->pcourse->id]));
        delete_course($this->pcourse, false);
        $this->assertSame(0, $DB->count_records('local_lticompletionsync_rcv'));
        $this->assertSame(0, $DB->count_records('local_lticompletionsync_act'));
        $this->assertSame(0, $DB->count_records('local_lticompletionsync_log', ['courseid' => $this->pcourse->id]));
        $this->assertGreaterThan(0, $DB->count_records('local_lticompletionsync_out'));

        delete_course($this->tcourse, false);
        $this->assertSame(0, $DB->count_records('local_lticompletionsync_out'));
        $this->assertSame(0, $DB->count_records('local_lticompletionsync_log', ['courseid' => $this->tcourse->id]));
    }

    /**
     * Deleting the published tool (enrol instance) removes its outbox rows at once; deleted LTI tool types and
     * deployments (no core events) are removed with their connections and rows by the sweep.
     */
    public function test_tool_type_and_deployment_deletion(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        $this->resetAfterTest();
        $this->flow();
        $instance = $DB->get_record('enrol', ['id' => $this->tresource->enrolid], '*', MUST_EXIST);
        enrol_get_plugin('lti')->delete_instance($instance);
        $this->assertSame(0, $DB->count_records('local_lticompletionsync_out', ['toolid' => $this->tresource->id]));

        lti_delete_type($this->ptype->id);
        $DB->delete_records('enrol_lti_deployment', ['id' => $this->tdeployment->id]);
        $counts = orphan_cleanup::sweep();
        $this->assertSame(2, $counts['connections']);
        $this->assertSame(0, $DB->count_records(connection::TABLE));
        $this->assertSame(0, $DB->count_records('local_lticompletionsync_rcv'));
        $this->assertSame(0, $DB->count_records('local_lticompletionsync_jti'));
        // A second run finds nothing.
        $this->assertSame([], array_filter(orphan_cleanup::sweep()));
    }

    /**
     * The sweep is bounded per run; the upgrade step to 2026102701 removes all existing orphans; the cleanup task runs it.
     */
    public function test_sweep_is_bounded_and_upgrade_cleans(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->flow();
        $now = time();
        for ($i = 1; $i <= 7; $i++) {
            $DB->insert_record('local_lticompletionsync_rcv', (object) ['connectionid' => $this->receiver->id,
                'cmid' => 900000 + $i, 'userid' => $this->plearner->id, 'status' => 'applied',
                'correlationid' => \core\uuid::generate(), 'timecreated' => $now, 'timemodified' => $now]);
        }
        $this->assertSame(3, orphan_cleanup::sweep(3)['rcv_cm']);
        $this->assertSame(3, orphan_cleanup::sweep(3)['rcv_cm']);
        $this->assertSame(1, $DB->count_records_select('local_lticompletionsync_rcv', 'cmid > 900000'));

        $DB->insert_record('local_lticompletionsync_act', (object) ['cmid' => 900100, 'enabled' => 1, 'lockedroleid' => 0,
            'usermodified' => 0, 'timecreated' => $now, 'timemodified' => $now]);
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/lticompletionsync/db/upgrade.php');
        set_config('version', 2026102700, 'local_lticompletionsync');
        ob_start();
        $this->assertTrue(xmldb_local_lticompletionsync_upgrade(2026102700));
        ob_end_clean();
        $this->assertSame(0, $DB->count_records_select('local_lticompletionsync_rcv', 'cmid > 900000'));
        $this->assertSame(0, $DB->count_records('local_lticompletionsync_act', ['cmid' => 900100]));
        // Live rows are kept.
        $this->assertSame(1, $DB->count_records('local_lticompletionsync_rcv', ['cmid' => $this->pcm->id]));

        $DB->insert_record('local_lticompletionsync_act', (object) ['cmid' => 900200, 'enabled' => 1, 'lockedroleid' => 0,
            'usermodified' => 0, 'timecreated' => $now, 'timemodified' => $now]);
        ob_start();
        (new \local_lticompletionsync\task\cleanup_task())->execute();
        $output = ob_get_clean();
        $this->assertStringContainsString('"act":1', $output);
        $this->assertSame(1, $DB->count_records('local_lticompletionsync_act'));
    }
}
