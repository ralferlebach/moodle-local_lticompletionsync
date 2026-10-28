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
 * CS-02: the learner lockdown and the explicit role overrides of administrators and teachers.
 *
 * Question of the audit: does activating the CAP_PROHIBIT overwrite an existing explicit override of the module
 * context, and does releasing restore it? Answer for 0.1.0: yes it overwrote, and no, the release deleted it
 * (test_v010_algorithm_lost_the_prior_override, a re-enactment of the 0.1.0 code). Since 0.2.0 the prior override
 * is remembered and restored exactly; later administrator decisions win; other roles are never touched.
 *
 * @package    local_lticompletionsync
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_lticompletionsync\local\completion_adapter
 * @covers     \local_lticompletionsync\local\receiver\activity_manager
 */
final class lockdown_overrides_test extends \advanced_testcase {
    use sync_env;

    /**
     * All role_capabilities rows of the toggle capability in a context, keyed by role.
     *
     * @param \context $context
     * @return array roleid => permission
     */
    private function overrides(\context $context): array {
        global $DB;
        return array_map('intval', $DB->get_records_menu('role_capabilities', [
            'contextid' => $context->id, 'capability' => completion_adapter::TOGGLE_CAPABILITY,
        ], 'roleid', 'roleid, permission'));
    }

    /**
     * Role id by short name.
     *
     * @param string $shortname
     * @return int
     */
    private function role(string $shortname): int {
        global $DB;
        return (int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
    }

    /**
     * Evidence for the audit question: the 0.1.0 algorithm (assign with overwrite, then unassign) replaced an existing
     * explicit override of the authenticated user role and deleted it on release.
     */
    public function test_v010_algorithm_lost_the_prior_override(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->build_env(['automanage' => false]);
        $context = $this->pcm->context;
        $roleid = (int) $CFG->defaultuserroleid;
        assign_capability(completion_adapter::TOGGLE_CAPABILITY, CAP_ALLOW, $roleid, $context->id, true);
        // 0.1.0 lockdown() and release_lockdown().
        assign_capability(completion_adapter::TOGGLE_CAPABILITY, CAP_PROHIBIT, $roleid, $context->id, true);
        $this->assertSame([$roleid => CAP_PROHIBIT], $this->overrides($context));
        unassign_capability(completion_adapter::TOGGLE_CAPABILITY, $roleid, $context->id);
        $this->assertSame([], $this->overrides($context), '0.1.0 deleted the administrator\'s CAP_ALLOW');
    }

    /**
     * Data provider: explicit overrides of the authenticated user role that existed before the management.
     *
     * @return array
     */
    public static function prior_provider(): array {
        return [
            'allow' => [CAP_ALLOW],
            'prevent' => [CAP_PREVENT],
            'prohibit' => [CAP_PROHIBIT],
            'none' => [null],
        ];
    }

    /**
     * The prior override is remembered, replaced by the prohibition while managed and restored exactly on release.
     *
     * @dataProvider prior_provider
     * @param int|null $prior
     */
    public function test_prior_override_is_restored_exactly(?int $prior): void {
        global $CFG;
        $this->resetAfterTest();
        $this->build_env(['automanage' => false]);
        $context = $this->pcm->context;
        $roleid = (int) $CFG->defaultuserroleid;
        if ($prior !== null) {
            assign_capability(completion_adapter::TOGGLE_CAPABILITY, $prior, $roleid, $context->id, true);
        }
        $expected = $this->overrides($context);

        $row = activity_manager::set_managed($this->pcm, true);
        $this->assertSame($roleid, (int) $row->lockedroleid);
        $this->assertSame(1, (int) $row->priorrecorded);
        $this->assertSame($prior, $row->priorpermission === null ? null : (int) $row->priorpermission);
        $this->assertSame([$roleid => CAP_PROHIBIT], $this->overrides($context));
        $this->assertSame([], completion_adapter::users_who_can_toggle($this->pcm)['sample']);
        $state = activity_manager::lockdown_state($this->pcm);
        $this->assertSame(CAP_PROHIBIT, $state['override']);
        $this->assertSame($prior, $state['prior']);

        $row = activity_manager::set_managed($this->pcm, false);
        $this->assertSame(0, (int) $row->lockedroleid);
        $this->assertSame($expected, $this->overrides($context));
        accesslib_clear_all_caches_for_unit_testing();
        $cantoggle = $prior === null || $prior === CAP_ALLOW;
        $this->assertSame($cantoggle, has_capability(completion_adapter::TOGGLE_CAPABILITY, $context, $this->plearner));
    }

    /**
     * An administrator who changes or removes the prohibition during the management keeps that decision: the release
     * does not overwrite it with the remembered prior override, and the lockdown is not written again behind his back.
     */
    public function test_later_administrator_decision_wins(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->build_env(['automanage' => false]);
        $context = $this->pcm->context;
        $roleid = (int) $CFG->defaultuserroleid;
        assign_capability(completion_adapter::TOGGLE_CAPABILITY, CAP_PREVENT, $roleid, $context->id, true);
        activity_manager::set_managed($this->pcm, true);

        // The administrator allows self-marking explicitly while the activity is managed.
        assign_capability(completion_adapter::TOGGLE_CAPABILITY, CAP_ALLOW, $roleid, $context->id, true);
        activity_manager::ensure_lockdown($this->pcm);
        $this->assertSame([$roleid => CAP_ALLOW], $this->overrides($context), 'no silent re-lock');
        $this->assertSame(1, activity_manager::lockdown_state($this->pcm)['users']);
        activity_manager::set_managed($this->pcm, false);
        $this->assertSame([$roleid => CAP_ALLOW], $this->overrides($context), 'the later decision is kept');

        // Removal during the management is kept as well.
        activity_manager::set_managed($this->pcm, true);
        unassign_capability(completion_adapter::TOGGLE_CAPABILITY, $roleid, $context->id);
        $outcome = completion_adapter::release_lockdown($context, $roleid, true, CAP_ALLOW);
        $this->assertSame(completion_adapter::RELEASE_KEPT, $outcome);
        $this->assertSame([], $this->overrides($context));
    }

    /**
     * Overrides of other roles (teacher, student) in the module context and the override capability of teachers are
     * not touched by lockdown and release; a teacher can still override a learner's completion (conflict policy).
     */
    public function test_other_roles_and_teacher_overrides_stay_controllable(): void {
        global $DB;
        $this->resetAfterTest();
        $this->build_env(['automanage' => false]);
        $context = $this->pcm->context;
        $student = $this->role('student');
        $teacher = $this->role('editingteacher');
        assign_capability(completion_adapter::TOGGLE_CAPABILITY, CAP_ALLOW, $student, $context->id, true);
        assign_capability('moodle/course:overridecompletion', CAP_ALLOW, $teacher, $context->id, true);
        $before = $DB->get_records_select(
            'role_capabilities',
            'contextid = :c AND roleid <> :r',
            ['c' => $context->id, 'r' => completion_adapter::lockdown_roleid()],
            'id',
            'id, roleid, capability, permission'
        );

        activity_manager::set_managed($this->pcm, true);
        $during = $DB->get_records_select(
            'role_capabilities',
            'contextid = :c AND roleid <> :r',
            ['c' => $context->id, 'r' => completion_adapter::lockdown_roleid()],
            'id',
            'id, roleid, capability, permission'
        );
        $this->assertEquals($before, $during);
        // The prohibition of the authenticated user role wins over the student role's allow (that is the lockdown).
        $this->assertFalse(has_capability(completion_adapter::TOGGLE_CAPABILITY, $context, $this->plearner));

        $teacheruser = $this->getDataGenerator()->create_and_enrol($this->pcourse, 'editingteacher');
        $this->setUser($teacheruser);
        \core_completion_external::override_activity_completion_status($this->plearner->id, $this->pcm->id, COMPLETION_COMPLETE);
        $this->assertSame(1, completion_adapter::current_state($this->pcm, (int) $this->plearner->id));
        $this->setAdminUser();

        activity_manager::set_managed($this->pcm, false);
        $after = $DB->get_records_select(
            'role_capabilities',
            'contextid = :c AND roleid <> :r',
            ['c' => $context->id, 'r' => completion_adapter::lockdown_roleid()],
            'id',
            'id, roleid, capability, permission'
        );
        $this->assertEquals($before, $after);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertTrue(has_capability(completion_adapter::TOGGLE_CAPABILITY, $context, $this->plearner));
    }

    /**
     * Uninstall restores the prior overrides of all managed activities; rows of 0.1.0 (prior unknown) are released
     * as 0.1.0 did.
     */
    public function test_uninstall_restores_prior_overrides(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/lticompletionsync/db/uninstall.php');
        $this->resetAfterTest();
        $this->build_env(['automanage' => false]);
        $roleid = (int) $CFG->defaultuserroleid;
        $context = $this->pcm->context;
        assign_capability(completion_adapter::TOGGLE_CAPABILITY, CAP_PREVENT, $roleid, $context->id, true);
        activity_manager::set_managed($this->pcm, true);

        $legacy = $this->getDataGenerator()->create_module('lti', ['course' => $this->pcourse->id,
            'typeid' => $this->ptype->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $legacyctx = \context_module::instance($legacy->cmid);
        assign_capability(completion_adapter::TOGGLE_CAPABILITY, CAP_PROHIBIT, $roleid, $legacyctx->id, true);
        $DB->insert_record('local_lticompletionsync_act', (object) ['cmid' => $legacy->cmid, 'enabled' => 1,
            'lockedroleid' => $roleid, 'priorrecorded' => 0, 'usermodified' => 0, 'timecreated' => time(),
            'timemodified' => time()]);

        $this->assertTrue(xmldb_local_lticompletionsync_uninstall());
        $this->assertSame([$roleid => CAP_PREVENT], $this->overrides($context));
        $this->assertSame([], $this->overrides($legacyctx));
    }

    /**
     * Duplicating a managed activity: the copy adopts the prohibition core copied, with the source's prior override,
     * so that releasing the copy restores what the source had before its lockdown.
     */
    public function test_duplicate_adopts_lockdown_with_prior(): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->resetAfterTest();
        $this->build_env(['automanage' => false]);
        $roleid = (int) $CFG->defaultuserroleid;
        assign_capability(completion_adapter::TOGGLE_CAPABILITY, CAP_ALLOW, $roleid, $this->pcm->context->id, true);
        activity_manager::set_managed($this->pcm, true);

        $actions = \core_courseformat\formatactions::cm($this->pcourse);
        $newcm = method_exists($actions, 'duplicate') ? $actions->duplicate((int) $this->pcm->id) :
            duplicate_module($this->pcourse, $this->pcm);
        ob_start();
        $this->runAdhocTasks('\\local_lticompletionsync\\task\\lockdown_task');
        ob_end_clean();
        $newcm = get_fast_modinfo($this->pcourse->id)->get_cm($newcm->id);
        $row = activity_manager::get((int) $newcm->id);
        $this->assertSame($roleid, (int) $row->lockedroleid);
        $this->assertSame(1, (int) $row->priorrecorded);
        $this->assertSame(CAP_ALLOW, (int) $row->priorpermission);
        $this->assertSame([$roleid => CAP_PROHIBIT], $this->overrides($newcm->context));

        activity_manager::set_managed($newcm, false);
        $this->assertSame([$roleid => CAP_ALLOW], $this->overrides($newcm->context));
        // The source is still managed and locked.
        $this->assertSame([$roleid => CAP_PROHIBIT], $this->overrides($this->pcm->context));
    }
}
