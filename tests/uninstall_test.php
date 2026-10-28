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
 * Uninstall cleanup: the learner lockdown (a core capability override) must not survive the plugin.
 *
 * @package    local_lticompletionsync
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_lticompletionsync\local\receiver\activity_manager
 */
final class uninstall_test extends \advanced_testcase {
    use sync_env;

    /**
     * Lockdowns written by the plugin and prohibitions copied by a duplication are removed; foreign overrides stay.
     */
    public function test_uninstall_removes_lockdowns(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/lticompletionsync/db/uninstall.php');
        $this->resetAfterTest();
        $this->build_env(['automanage' => false]);
        $gen = $this->getDataGenerator();
        activity_manager::set_managed($this->pcm, true);
        $this->assertSame([], completion_adapter::users_who_can_toggle($this->pcm)['sample']);

        // A managed copy whose prohibition was copied by core (deferred lockdown task not run yet: lockedroleid 0).
        $copy = $gen->create_module('lti', ['course' => $this->pcourse->id, 'typeid' => $this->ptype->id,
            'completion' => COMPLETION_TRACKING_MANUAL]);
        $copycm = get_fast_modinfo($this->pcourse->id)->get_cm($copy->cmid);
        $copyctx = \context_module::instance($copycm->id);
        $DB->insert_record('local_lticompletionsync_act', (object) ['cmid' => $copycm->id, 'enabled' => 1,
            'lockedroleid' => 0, 'usermodified' => 0, 'timecreated' => time(), 'timemodified' => time()]);
        $DB->insert_record('role_capabilities', (object) ['contextid' => $copyctx->id, 'roleid' => $CFG->defaultuserroleid,
            'capability' => completion_adapter::TOGGLE_CAPABILITY, 'permission' => CAP_PROHIBIT, 'timemodified' => time(),
            'modifierid' => 0]);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertSame([], completion_adapter::users_who_can_toggle($copycm)['sample']);

        // A teacher's own override in an unmanaged activity is not the plugin's business.
        $other = $gen->create_module('lti', ['course' => $this->pcourse->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $studentrole = $DB->get_field('role', 'id', ['shortname' => 'student']);
        assign_capability(
            completion_adapter::TOGGLE_CAPABILITY,
            CAP_PROHIBIT,
            $studentrole,
            \context_module::instance($other->cmid)->id,
            true
        );

        $this->assertTrue(xmldb_local_lticompletionsync_uninstall());
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertFalse($DB->record_exists_select(
            'role_capabilities',
            'capability = :cap AND contextid IN (:a, :b)',
            ['cap' => completion_adapter::TOGGLE_CAPABILITY,
            'a' => \context_module::instance($this->pcm->id)->id,
            'b' => $copyctx->id]
        ));
        $this->assertSame([(int) $this->plearner->id], completion_adapter::users_who_can_toggle($this->pcm)['sample']);
        $this->assertSame([(int) $this->plearner->id], completion_adapter::users_who_can_toggle($copycm)['sample']);
        $this->assertTrue($DB->record_exists('role_capabilities', ['contextid' => \context_module::instance($other->cmid)->id,
            'roleid' => $studentrole, 'capability' => completion_adapter::TOGGLE_CAPABILITY]));

        // The learner can mark the formerly managed activity himself again.
        $this->setUser($this->plearner);
        $result = \core_completion_external::update_activity_completion_status_manually($this->pcm->id, true);
        $this->assertTrue($result['status']);
    }
}
