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

namespace local_lticompletionsync\integration;

use local_lticompletionsync\local\connection;
use local_lticompletionsync\local\receiver\processor;
use local_lticompletionsync\local\sender\outbox;
use local_lticompletionsync\local\sender\reconciler;
use local_lticompletionsync\tests\loopback_http_client;
use local_lticompletionsync\tests\sync_env;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/sync_env.php');
require_once(__DIR__ . '/../fixtures/loopback_http_client.php');

/**
 * CS-05 / CS-G3: existing grades and their complete history stay byte-identical through every completion path.
 *
 * Snapshot = every row and every column of grade_items, grade_items_history, grade_grades, grade_grades_history,
 * grade_categories, grade_categories_history of both courses (platform and tool side) plus mod_lti's submissions.
 *
 * @package    local_lticompletionsync
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_lticompletionsync\local\receiver\processor
 * @covers     \local_lticompletionsync\local\completion_adapter
 * @covers     \local_lticompletionsync\local\sender\dispatcher
 */
final class grade_snapshot_test extends \advanced_testcase {
    use sync_env;

    /**
     * Full snapshot of the grade tables of the platform and the tool course.
     *
     * @return array
     */
    private function snapshot(): array {
        global $DB;
        $courses = [(int) $this->pcourse->id, (int) $this->tcourse->id];
        [$in, $params] = $DB->get_in_or_equal($courses, SQL_PARAMS_NAMED, 'c');
        $items = $DB->get_fieldset_select('grade_items', 'id', "courseid $in", $params);
        [$iin, $iparams] = $DB->get_in_or_equal($items ?: [0], SQL_PARAMS_NAMED, 'i');
        return [
            'grade_items' => $DB->get_records_select('grade_items', "courseid $in", $params, 'id'),
            'grade_items_history' => $DB->get_records_select('grade_items_history', "courseid $in", $params, 'id'),
            'grade_categories' => $DB->get_records_select('grade_categories', "courseid $in", $params, 'id'),
            'grade_categories_history' => $DB->get_records_select('grade_categories_history', "courseid $in", $params, 'id'),
            'grade_grades' => $DB->get_records_select('grade_grades', "itemid $iin", $iparams, 'id'),
            'grade_grades_history' => $DB->get_records_select('grade_grades_history', "itemid $iin", $iparams, 'id'),
            'lti_submission' => $DB->get_records('lti_submission', null, 'id'),
        ];
    }

    /**
     * Every path - completion, duplicate, revocation, out-of-order, teacher override re-asserted, sweep, full
     * reconcile, force resync - leaves the snapshot unchanged; the native completion follows the provider.
     */
    public function test_grades_and_history_unchanged_on_every_path(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        $this->build_env();
        // Existing grades with history: platform activity (two updates) and tool course item.
        foreach ([77, 81] as $grade) {
            grade_update(
                'mod/lti',
                $this->pcourse->id,
                'mod',
                'lti',
                $this->pcm->instance,
                0,
                [$this->plearner->id => (object) ['userid' => $this->plearner->id, 'rawgrade' => $grade]]
            );
        }
        $before = $this->snapshot();
        $this->assertNotEmpty($before['grade_grades_history']);
        $http = new loopback_http_client();

        $tooluser = $this->launch($this->plearner, $this->tresource);
        $this->assertEquals($before, $this->snapshot(), 'launch');
        $this->tool_complete($tooluser, true);
        $this->deliver_all($http);
        $this->assertSame(1, $this->platform_state());
        $this->assertEquals($before, $this->snapshot(), 'completion applied');

        // Duplicate and out-of-order replays of the posted messages.
        foreach ($http->posted as $jws) {
            (new \local_lticompletionsync\local\receiver\endpoint())->handle($jws);
        }
        $this->tool_complete($tooluser, false);
        $this->deliver_all($http);
        $this->assertSame(0, $this->platform_state());
        $this->assertEquals($before, $this->snapshot(), 'revocation');

        // Teacher override, re-asserted by the provider policy (ad hoc task).
        $teacher = $this->getDataGenerator()->create_and_enrol($this->pcourse, 'editingteacher');
        $this->setUser($teacher);
        \core_completion_external::override_activity_completion_status($this->plearner->id, $this->pcm->id, COMPLETION_COMPLETE);
        $this->setAdminUser();
        ob_start();
        $this->runAdhocTasks('\\local_lticompletionsync\\task\\reassert_task');
        ob_end_clean();
        $this->assertSame(0, $this->platform_state());
        $this->assertEquals($before, $this->snapshot(), 'override re-asserted');

        reconciler::sweep(0);
        reconciler::full();
        reconciler::full(0, true);
        $this->deliver_all($http);
        $this->assertEquals($before, $this->snapshot(), 'sweep, reconcile, resync');
        $this->assertSame(outbox::STATUS_ACKED, $this->outrow($tooluser)->status);
        $this->assertGreaterThanOrEqual(3, (int) $this->outrow($tooluser)->version);
        $this->assertSame(0, $this->platform_state());
        $this->assertSame(1, $DB->count_records(processor::TABLE));
        $this->assertSame('cooperative', \local_lticompletionsync\local\status_report::level(
            connection::get((int) $this->sender->id)
        )['key']);
    }
}
