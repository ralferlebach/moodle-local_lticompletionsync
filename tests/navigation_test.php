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

/**
 * Navigation callbacks: activity page link for teachers of LTI activities, course status link.
 *
 * @package    local_lticompletionsync
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::local_lticompletionsync_extend_settings_navigation
 * @covers     ::local_lticompletionsync_extend_navigation_course
 */
final class navigation_test extends \advanced_testcase {
    /**
     * Settings navigation of an LTI activity page for a user.
     *
     * @param \stdClass $course
     * @param \cm_info $cm
     * @return \settings_navigation
     */
    private function settingsnav(\stdClass $course, \cm_info $cm): \settings_navigation {
        global $PAGE;
        $PAGE = new \moodle_page();
        $PAGE->set_cm($cm, $course);
        $PAGE->set_context(\context_module::instance($cm->id));
        $PAGE->set_url(new \moodle_url('/mod/lti/view.php', ['id' => $cm->id]));
        $nav = new \settings_navigation($PAGE);
        $nav->initialise();
        return $nav;
    }

    /**
     * Teachers get the activity page link, learners do not.
     */
    public function test_activity_link(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['enablecompletion' => 1]);
        $teacher = $gen->create_and_enrol($course, 'editingteacher');
        $student = $gen->create_and_enrol($course, 'student');
        $lti = $gen->create_module('lti', ['course' => $course->id, 'completion' => 1]);
        $cm = get_fast_modinfo($course)->get_cm($lti->cmid);

        $this->setUser($teacher);
        $node = $this->settingsnav($course, $cm)->find('local_lticompletionsync_activity', \navigation_node::TYPE_SETTING);
        $this->assertNotFalse($node);
        $this->assertStringContainsString('/local/lticompletionsync/activity.php', $node->action->out(false));

        $this->setUser($student);
        $this->assertFalse($this->settingsnav($course, $cm)->find(
            'local_lticompletionsync_activity',
            \navigation_node::TYPE_SETTING
        ));
    }

    /**
     * Teachers get the course status link.
     */
    public function test_course_link(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/lticompletionsync/lib.php');
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $teacher = $gen->create_and_enrol($course, 'teacher');
        $student = $gen->create_and_enrol($course, 'student');
        $this->setUser($teacher);
        $root = new \navigation_node(['text' => 'root', 'key' => 'root']);
        local_lticompletionsync_extend_navigation_course($root, $course, \context_course::instance($course->id));
        $this->assertNotFalse($root->find('local_lticompletionsync_status', \navigation_node::TYPE_SETTING));
        $this->setUser($student);
        $root = new \navigation_node(['text' => 'root', 'key' => 'root']);
        local_lticompletionsync_extend_navigation_course($root, $course, \context_course::instance($course->id));
        $this->assertFalse($root->find('local_lticompletionsync_status', \navigation_node::TYPE_SETTING));
    }
}
