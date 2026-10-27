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

use local_lticompletionsync\local\completion_adapter;
use local_lticompletionsync\local\receiver\activity_manager;
use local_lticompletionsync\tests\sync_env;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/sync_env.php');

/**
 * Backup and restore of the per-activity setting (and re-applied lockdown), activity deletion cleanup.
 *
 * @package    local_lticompletionsync
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_lticompletionsync\local\receiver\activity_manager
 */
final class backup_restore_test extends \advanced_testcase {
    use sync_env;

    /**
     * Duplicating a managed activity (backup + restore of one module) keeps it managed with lockdown.
     */
    public function test_duplicate_keeps_management(): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->resetAfterTest();
        $this->build_env(['automanage' => false]);
        activity_manager::set_managed($this->pcm, true);
        $this->assertSame([], completion_adapter::users_who_can_toggle($this->pcm));

        $actions = \core_courseformat\formatactions::cm($this->pcourse);
        if (method_exists($actions, 'duplicate')) {
            // Moodle 5.2+.
            $newcm = $actions->duplicate((int) $this->pcm->id);
        } else {
            $newcm = duplicate_module($this->pcourse, $this->pcm);
        }
        $newcm = get_fast_modinfo($this->pcourse->id)->get_cm($newcm->id);
        $row = activity_manager::get((int) $newcm->id);
        $this->assertNotEmpty($row);
        $this->assertEquals(1, $row->enabled);
        // Core copies the permission override of the original after the restore; the deferred task records it.
        ob_start();
        $this->runAdhocTasks('\\local_lticompletionsync\\task\\lockdown_task');
        ob_end_clean();
        $this->assertGreaterThan(0, (int) activity_manager::get((int) $newcm->id)->lockedroleid);
        $this->assertSame([], completion_adapter::users_who_can_toggle($newcm));
    }

    /**
     * Backup of one activity and restore into another course: the setting travels, the lockdown is written by the
     * deferred task, transfer states do not travel.
     */
    public function test_backup_and_restore_into_other_course(): void {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        $this->resetAfterTest();
        $this->build_env(['automanage' => false]);
        activity_manager::set_managed($this->pcm, true);
        $target = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $learner = $this->getDataGenerator()->create_and_enrol($target, 'student');

        $bc = new \backup_controller(
            \backup::TYPE_1ACTIVITY,
            $this->pcm->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id
        );
        $bc->get_plan()->get_setting('role_assignments')->set_value(false);
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();
        $rc = new \restore_controller(
            $backupid,
            $target->id,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id,
            \backup::TARGET_EXISTING_ADDING
        );
        $rc->execute_precheck();
        $this->assertArrayNotHasKey('errors', $rc->get_precheck_results());
        $rc->execute_plan();
        $rc->destroy();

        $newcmid = $DB->get_field('course_modules', 'id', ['course' => $target->id,
            'module' => $DB->get_field('modules', 'id', ['name' => 'lti'])], MUST_EXIST);
        $row = activity_manager::get((int) $newcmid);
        $this->assertNotEmpty($row);
        $this->assertEquals(1, $row->enabled);
        ob_start();
        $this->runAdhocTasks('\\local_lticompletionsync\\task\\lockdown_task');
        ob_end_clean();
        $newcm = get_fast_modinfo($target->id)->get_cm((int) $newcmid);
        $this->assertSame([], completion_adapter::users_who_can_toggle($newcm));
        $this->assertFalse($DB->record_exists('local_lticompletionsync_rcv', ['cmid' => $newcmid]));
        $this->assertNotEmpty($learner);
    }

    /**
     * Switching management off removes the lockdown again; deleting the activity removes the plugin data.
     */
    public function test_release_and_delete(): void {
        global $DB;
        $this->resetAfterTest();
        $this->build_env(['automanage' => false]);
        activity_manager::set_managed($this->pcm, true);
        activity_manager::set_managed($this->pcm, false);
        $this->assertSame([(int) $this->plearner->id], completion_adapter::users_who_can_toggle($this->pcm));
        $this->assertFalse(activity_manager::is_managed($this->pcm));
        $actions = \core_courseformat\formatactions::cm($this->pcourse);
        if (method_exists($actions, 'delete')) {
            // Moodle 5.2+.
            $actions->delete((int) $this->pcm->id);
        } else {
            course_delete_module($this->pcm->id);
        }
        $this->assertFalse($DB->record_exists('local_lticompletionsync_act', ['cmid' => $this->pcm->id]));
    }

    /**
     * Activities that cannot be managed are refused with the reason.
     */
    public function test_unsupported_activity_refused(): void {
        $this->resetAfterTest();
        $this->build_env();
        $page = get_fast_modinfo($this->tcourse->id)->get_cm($this->tpage->cmid);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('unsupported_not_lti', 'local_lticompletionsync'));
        activity_manager::set_managed($page, true);
    }
}
