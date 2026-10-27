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

namespace local_lticompletionsync\gates;

use local_lticompletionsync\local\completion_adapter;

/**
 * Evidence for the no-fork gate CS-G4 (spec 08, section 5, steps 1 to 4) on the running Moodle version.
 *
 * Every test runs against the real core of the tree it is executed in (4.5 and 5.3 locally; 5.0 to 5.2 in the
 * acceptance matrix). Positive and negative findings are both asserted, so a change of core behaviour turns the
 * suite red instead of silently changing what the plugin claims.
 *
 * @package    local_lticompletionsync
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_lticompletionsync\local\completion_adapter
 */
final class cs_g4_gate_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;
    /** @var \stdClass */
    private $student;
    /** @var \stdClass */
    private $teacher;

    /**
     * Course with completion, a student and an editing teacher.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/mod/lti/lib.php');
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['enablecompletion' => 1]);
        $this->student = $gen->create_and_enrol($this->course, 'student');
        $this->teacher = $gen->create_and_enrol($this->course, 'editingteacher');
    }

    /**
     * Create an LTI activity.
     *
     * @param int $tracking COMPLETION_TRACKING_* constant
     * @param array $extra further module fields
     * @return \cm_info
     */
    private function lti(int $tracking, array $extra = []): \cm_info {
        $lti = $this->getDataGenerator()->create_module('lti', array_merge([
            'course' => $this->course->id,
            'completion' => $tracking,
            'grade' => 100,
        ], $extra));
        return get_fast_modinfo($this->course->id)->get_cm($lti->cmid);
    }

    /**
     * Give the student a grade in the activity's grade item (as AGS would).
     *
     * @param \cm_info $cm
     * @param float $grade
     */
    private function grade(\cm_info $cm, float $grade): void {
        $grades = [$this->student->id => (object) ['userid' => $this->student->id, 'rawgrade' => $grade]];
        $this->assertSame(GRADE_UPDATE_OK, grade_update('mod/lti', $this->course->id, 'mod', 'lti', $cm->instance, 0, $grades));
    }

    /**
     * Snapshot of everything gradebook related for a user (grades, history, items).
     *
     * @param int $userid
     * @return array
     */
    private function grade_snapshot(int $userid): array {
        global $DB;
        return [
            'grades' => $DB->get_records(
                'grade_grades',
                ['userid' => $userid],
                'id',
                'id, itemid, rawgrade, finalgrade, timemodified'
            ),
            'history' => $DB->count_records('grade_grades_history', ['userid' => $userid]),
            'items' => $DB->get_records(
                'grade_items',
                ['courseid' => $this->course->id],
                'id',
                'id, itemtype, itemmodule, iteminstance'
            ),
        ];
    }

    /**
     * Raw completion row (read only).
     *
     * @param \cm_info $cm
     * @param int $userid
     * @return \stdClass
     */
    private function data(\cm_info $cm, int $userid): \stdClass {
        $completion = new \completion_info($this->course);
        return $completion->get_data($cm, false, $userid);
    }

    /**
     * Preconditions shared by every step: mod_lti offers no completion rules of its own.
     */
    public function test_mod_lti_has_no_completion_rules(): void {
        $this->assertNull(plugin_supports('mod', 'lti', FEATURE_COMPLETION_HAS_RULES));
        $this->assertTrue((bool) plugin_supports('mod', 'lti', FEATURE_COMPLETION_TRACKS_VIEWS));
        $this->assertTrue((bool) plugin_supports('mod', 'lti', FEATURE_GRADE_HAS_GRADE));
        $this->assertFalse(class_exists('\\mod_lti\\completion\\custom_completion'));
    }

    /**
     * Step 1: manual completion is set and revoked by a server-side call for one user, without any capability of the
     * acting user and without grade operations; an existing grade stays untouched.
     */
    public function test_step1_manual_set_and_revoke_without_grade_operations(): void {
        $cm = $this->lti(COMPLETION_TRACKING_MANUAL);
        // An existing grade must survive.
        $this->grade($cm, 42);
        $before = $this->grade_snapshot($this->student->id);
        // Activity grade and course total.
        $this->assertCount(2, $before['grades']);

        // The acting user has no capabilities at all (not logged in, like a token-authenticated endpoint).
        $this->setUser(0);
        $sink = $this->redirectEvents();
        $this->assertSame(completion_adapter::RESULT_APPLIED, completion_adapter::apply($cm, (int) $this->student->id, true));
        $this->assertSame(1, completion_adapter::current_state($cm, (int) $this->student->id));
        $this->assertNull($this->data($cm, (int) $this->student->id)->overrideby);
        $events = array_filter($sink->get_events(), fn($e) => $e instanceof \core\event\course_module_completion_updated);
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertEquals($this->student->id, $event->relateduserid);
        $this->assertEquals(COMPLETION_COMPLETE, $event->other['completionstate']);

        // Idempotent: the same state again changes nothing and fires nothing.
        $sink->clear();
        $this->assertSame(completion_adapter::RESULT_UNCHANGED, completion_adapter::apply($cm, (int) $this->student->id, true));
        $events = array_filter($sink->get_events(), fn($e) => $e instanceof \core\event\course_module_completion_updated);
        $this->assertCount(0, $events);

        // Revocation.
        $this->assertSame(completion_adapter::RESULT_APPLIED, completion_adapter::apply($cm, (int) $this->student->id, false));
        $this->assertSame(0, completion_adapter::current_state($cm, (int) $this->student->id));
        $sink->close();

        // Grades, grade history and grade items are exactly as before.
        $this->assertEquals($before, $this->grade_snapshot($this->student->id));
        // Other users are not affected.
        $this->assertSame(0, completion_adapter::current_state($cm, (int) $this->teacher->id));
    }

    /**
     * Step 1 (persistence): a revocation survives cache purges and the completion cron tasks.
     */
    public function test_step1_revocation_survives_cron_and_cache_purge(): void {
        $cm = $this->lti(COMPLETION_TRACKING_MANUAL);
        completion_adapter::apply($cm, (int) $this->student->id, true);
        completion_adapter::apply($cm, (int) $this->student->id, false);
        purge_all_caches();
        $task = new \core\task\completion_regular_task();
        ob_start();
        $task->execute();
        ob_end_clean();
        $cm = get_fast_modinfo($this->course->id)->get_cm($cm->id);
        $this->assertSame(0, completion_adapter::current_state($cm, (int) $this->student->id));
    }

    /**
     * Step 1 (grades): a grade change on a manual activity never changes its completion.
     */
    public function test_step1_grade_change_does_not_touch_manual_completion(): void {
        $cm = $this->lti(COMPLETION_TRACKING_MANUAL);
        completion_adapter::apply($cm, (int) $this->student->id, true);
        $this->grade($cm, 10);
        $this->assertSame(1, completion_adapter::current_state($cm, (int) $this->student->id));
    }

    /**
     * Step 2 (control): without the lockdown the learner can toggle the manual completion himself.
     */
    public function test_step2_learner_can_toggle_without_lockdown(): void {
        $cm = $this->lti(COMPLETION_TRACKING_MANUAL);
        $this->setUser($this->student);
        $result = \core_completion_external::update_activity_completion_status_manually($cm->id, true);
        $this->assertTrue($result['status']);
        $this->assertSame(1, completion_adapter::current_state($cm, (int) $this->student->id));
    }

    /**
     * Step 2: CAP_PREVENT for the student role alone does not stop self-toggling (authenticated user role allows).
     */
    public function test_step2_prevent_for_student_role_is_not_enough(): void {
        global $DB;
        $cm = $this->lti(COMPLETION_TRACKING_MANUAL);
        $studentrole = $DB->get_field('role', 'id', ['shortname' => 'student']);
        $context = \context_module::instance($cm->id);
        assign_capability(completion_adapter::TOGGLE_CAPABILITY, CAP_PREVENT, $studentrole, $context->id, true);
        $this->assertTrue(has_capability(completion_adapter::TOGGLE_CAPABILITY, $context, $this->student));
    }

    /**
     * Step 2: the lockdown (CAP_PROHIBIT for the authenticated user role in the module context) blocks the web
     * service path, while the server-side adapter still sets and revokes the state.
     */
    public function test_step2_lockdown_blocks_learner_toggle_but_not_the_adapter(): void {
        $cm = $this->lti(COMPLETION_TRACKING_MANUAL);
        $roleid = completion_adapter::lockdown($cm);
        $this->assertGreaterThan(0, $roleid);
        $this->assertSame([], completion_adapter::users_who_can_toggle($cm));

        $this->setUser($this->student);
        try {
            \core_completion_external::update_activity_completion_status_manually($cm->id, true);
            $this->fail('The learner must not be able to toggle');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
        $this->assertSame(0, completion_adapter::current_state($cm, (int) $this->student->id));

        // The plugin path does not depend on the acting user.
        $this->assertSame(completion_adapter::RESULT_APPLIED, completion_adapter::apply($cm, (int) $this->student->id, true));
        $this->setUser($this->student);
        try {
            \core_completion_external::update_activity_completion_status_manually($cm->id, false);
            $this->fail('The learner must not be able to revoke');
        } catch (\required_capability_exception $e) {
            $this->assertSame(1, completion_adapter::current_state($cm, (int) $this->student->id));
        }

        // The lockdown is scoped to the activity: another activity of the course stays self-toggleable.
        $other = $this->lti(COMPLETION_TRACKING_MANUAL);
        $othercontext = \context_module::instance($other->id);
        $this->assertTrue(has_capability(completion_adapter::TOGGLE_CAPABILITY, $othercontext, $this->student));

        // And it is reversible.
        completion_adapter::release_lockdown($cm, $roleid);
        $this->assertSame([(int) $this->student->id], completion_adapter::users_who_can_toggle($cm));
    }

    /**
     * Step 2: every learner entry point checks the capability before update_state() (static evidence from the core
     * sources of this tree: the legacy toggle script and the web service used by the course page and the app).
     */
    public function test_step2_all_learner_toggle_paths_check_the_capability(): void {
        global $CFG;
        $paths = [
            $CFG->dirroot . '/course/togglecompletion.php',
            $CFG->dirroot . '/completion/classes/external.php',
        ];
        foreach ($paths as $path) {
            $src = file_get_contents($path);
            $cap = strpos($src, "require_capability('moodle/course:togglecompletion'");
            $update = strpos($src, '->update_state($cm, $targetstate)');
            $this->assertNotFalse($cap, $path);
            $this->assertNotFalse($update, $path);
            $this->assertLessThan($update, $cap, $path);
        }
        // No other core code calls update_state() with a learner-chosen target state for manual tracking: all other
        // callers are module-specific automatic rules, overrides (separate capability) or the view trigger.
    }

    /**
     * Step 2 (side effect): core still renders the manual completion button for a locked-down learner; the click
     * then fails server side. This is a documented UX limitation, not hidden with CSS.
     */
    public function test_step2_side_effect_button_still_rendered(): void {
        $cm = $this->lti(COMPLETION_TRACKING_MANUAL);
        completion_adapter::lockdown($cm);
        $details = \core_completion\cm_completion_details::get_instance($cm, (int) $this->student->id);
        $this->assertTrue($details->is_manual());
        $this->assertTrue($details->show_manual_completion());
        $this->assertTrue($details->is_tracked_user());
    }

    /**
     * Step 2: a teacher override (moodle/course:overridecompletion) remains possible and is identifiable (overrideby),
     * so it can be handled as an explicit conflict rule instead of being silently overwritten.
     */
    public function test_step2_teacher_override_is_possible_and_identifiable(): void {
        $cm = $this->lti(COMPLETION_TRACKING_MANUAL);
        completion_adapter::lockdown($cm);
        $this->setUser($this->teacher);
        \core_completion_external::override_activity_completion_status($this->student->id, $cm->id, COMPLETION_COMPLETE);
        $this->assertSame(1, completion_adapter::current_state($cm, (int) $this->student->id));
        $this->assertSame((int) $this->teacher->id, completion_adapter::overridden_by($cm, (int) $this->student->id));
        // The adapter can revoke it again (provider-authoritative policy); overrideby is cleared by core.
        $this->setUser(0);
        completion_adapter::apply($cm, (int) $this->student->id, false);
        $this->assertSame(0, completion_adapter::current_state($cm, (int) $this->student->id));
        $this->assertNull(completion_adapter::overridden_by($cm, (int) $this->student->id));
    }

    /**
     * Step 3: automatic tracking cannot be driven from outside. Without the override flag core recomputes the
     * activity's own conditions; the override flag needs moodle/course:overridecompletion of the acting user.
     */
    public function test_step3_automatic_tracking_is_not_externally_controllable(): void {
        $cm = $this->lti(COMPLETION_TRACKING_AUTOMATIC, ['completionview' => COMPLETION_VIEW_REQUIRED]);
        $this->assertSame(completion_adapter::REASON_TRACKING_AUTOMATIC, completion_adapter::unsupported_reason($cm));
        $completion = new \completion_info($this->course);

        // Without override: the requested COMPLETE is ignored, the view condition decides.
        $this->setUser(0);
        $completion->update_state($cm, COMPLETION_COMPLETE, $this->student->id);
        $this->assertSame(0, completion_adapter::current_state($cm, (int) $this->student->id));

        // With override and without the capability: exception.
        try {
            $completion->update_state($cm, COMPLETION_COMPLETE, $this->student->id, true);
            $this->fail('Override must need moodle/course:overridecompletion');
        } catch (\required_capability_exception $e) {
            $this->assertSame(0, completion_adapter::current_state($cm, (int) $this->student->id));
        }

        // The adapter refuses automatic tracking explicitly.
        $this->expectException(\moodle_exception::class);
        completion_adapter::apply($cm, (int) $this->student->id, true);
    }

    /**
     * Step 3: even with the capability, an override to INCOMPLETE does not hold against the activity's own
     * automatic trigger (here: the view condition), so a revocation would not be stable.
     */
    public function test_step3_override_revocation_is_not_stable_under_automatic_tracking(): void {
        $cm = $this->lti(COMPLETION_TRACKING_AUTOMATIC, ['completionview' => COMPLETION_VIEW_REQUIRED]);
        $completion = new \completion_info($this->course);
        $this->setAdminUser();
        // The learner viewed the activity: complete.
        $completion->set_module_viewed($cm, $this->student->id);
        $this->assertSame(1, completion_adapter::current_state($cm, (int) $this->student->id));
        // Override to incomplete (revocation).
        $completion->update_state($cm, COMPLETION_INCOMPLETE, $this->student->id, true);
        $this->assertSame(0, completion_adapter::current_state($cm, (int) $this->student->id));
        // The next view (a new launch) re-completes it: the revocation is lost.
        get_fast_modinfo($this->course->id, 0, true);
        $cm = get_fast_modinfo($this->course->id)->get_cm($cm->id);
        $completion->set_module_viewed($cm, $this->student->id);
        $this->assertSame(1, completion_adapter::current_state($cm, (int) $this->student->id));
    }

    /**
     * Step 3: the 5.0+ hook exists exactly from 5.0 on and is dispatched after the write (no veto possible), so it is
     * no extension point for an external completion rule.
     */
    public function test_step3_completion_hook_presence_matches_version(): void {
        global $CFG;
        $expected = (int) $CFG->branch >= 500;
        $this->assertSame($expected, completion_adapter::has_completion_hook());
        if ($expected) {
            $rc = new \ReflectionClass('\\core_completion\\hook\\after_cm_completion_updated');
            $this->assertFalse($rc->implementsInterface('\\Psr\\EventDispatcher\\StoppableEventInterface'));
            $src = file_get_contents($CFG->libdir . '/completionlib.php');
            $hookpos = strpos($src, 'new \\core_completion\\hook\\after_cm_completion_updated(');
            $writepos = strpos($src, '$this->internal_set_data($cm, $current, $isbulkupdate);');
            $this->assertNotFalse($hookpos);
            $this->assertLessThan($hookpos, $writepos);
        }
    }

    /**
     * Step 4: the adapter never writes course_modules_completion itself (static check of the plugin sources).
     */
    public function test_step4_no_direct_completion_table_writes_in_plugin(): void {
        global $CFG;
        $dir = $CFG->dirroot . '/local/lticompletionsync';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        $offenders = [];
        foreach ($it as $file) {
            $path = $file->getPathname();
            if (substr($path, -4) !== '.php' || strpos($path, '/tests/') !== false) {
                continue;
            }
            $src = file_get_contents($path);
            $tablewrite = '/(insert_record|update_record|delete_records|set_field|execute)\w*\(\s*[\'"]course_modules_completion/';
            if (preg_match($tablewrite, $src)) {
                $offenders[] = $path;
            }
            if (preg_match('/(insert_record|update_record|delete_records|set_field)\w*\(\s*[\'"]grade_(grades|items)/', $src)) {
                $offenders[] = $path;
            }
        }
        $this->assertSame([], $offenders);
    }
}
