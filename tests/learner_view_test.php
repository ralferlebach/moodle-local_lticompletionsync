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

use core\hook\output\after_http_headers;
use core\hook\output\before_http_headers;
use local_lticompletionsync\local\completion_adapter;
use local_lticompletionsync\local\receiver\activity_manager;
use local_lticompletionsync\local\receiver\learner_view;
use local_lticompletionsync\tests\sync_env;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/sync_env.php');

/**
 * CS-02 learner UI: no dead-end "Mark as done" control for a locked-down learner, through the supported core paths.
 *
 * The hooks are dispatched through core's hook manager (proves the registration in db/hooks.php) on real moodle_page
 * objects; the activity header is exported by core's own activity_header class, the course page control by core's own
 * course format output class.
 *
 * @package    local_lticompletionsync
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_lticompletionsync\local\receiver\learner_view
 * @covers     \local_lticompletionsync\hook_callbacks
 */
final class learner_view_test extends \advanced_testcase {
    use sync_env;

    /**
     * A page as mod/lti/view.php or course/view.php sets it up.
     *
     * @param \cm_info|null $cm activity page when given, course page otherwise
     * @return \moodle_page
     */
    private function page(?\cm_info $cm): \moodle_page {
        global $PAGE;
        // Core's completion details read the global page (cm_completion_details::show_manual_completion()).
        $page = new \moodle_page();
        $PAGE = $page;
        if ($cm) {
            // As mod/lti/view.php: the course module as the current user sees it.
            $cm = get_fast_modinfo($this->pcourse->id)->get_cm($cm->id);
            $page->set_url('/mod/lti/view.php', ['id' => $cm->id]);
            $page->set_cm($cm, $this->pcourse);
            $page->set_pagetype('mod-lti-view');
            $page->set_pagelayout('incourse');
        } else {
            $page->set_url('/course/view.php', ['id' => $this->pcourse->id]);
            $page->set_course($this->pcourse);
            $page->set_pagetype('course-view-' . $this->pcourse->format);
            $page->set_pagelayout('course');
        }
        return $page;
    }

    /**
     * Dispatch before_http_headers and after_http_headers like core_renderer::header() does.
     *
     * @param \moodle_page $page
     * @return array [renderer, html added after the header]
     */
    private function header(\moodle_page $page): array {
        $renderer = $page->get_renderer('core');
        \core\di::get(\core\hook\manager::class)->dispatch(new before_http_headers($renderer));
        $after = new after_http_headers($renderer, '');
        \core\di::get(\core\hook\manager::class)->dispatch($after);
        return [$renderer, $after->get_output()];
    }

    /**
     * Manual completion controls core puts into the activity header: 4.5 inside the header's completion block, 5.x as
     * a page header action.
     *
     * @param \moodle_page $page
     * @param array $header exported activity header
     * @return string
     */
    private function header_controls(\moodle_page $page, array $header): string {
        return (string) ($header['completion'] ?? '') . implode('', $page->get_header_actions());
    }

    /**
     * The 5.3 linear navigation footer (rendered by core's own hook listener when the course shows it; no hook for
     * plugins there, see DOCUMENTATION 2.1).
     *
     * @param \moodle_page $page
     * @param \renderer_base $renderer
     * @return string
     */
    private function footer_controls(\moodle_page $page, \renderer_base $renderer): string {
        $settings = '\\core_courseformat\\local\\linearnavigationsettings';
        $footer = '\\core_courseformat\\output\\local\\linearnavigation\\footer_content';
        if (!class_exists($settings) || !$settings::show_navigation_footer($page)) {
            return '';
        }
        return $renderer->render(new $footer($page->cm));
    }

    /**
     * The inline JavaScript the page requires (footer code).
     *
     * @param \moodle_page $page
     * @return string
     */
    private function inline_js(\moodle_page $page): string {
        $property = new \ReflectionProperty($page->requires, 'amdjscode');
        $property->setAccessible(true);
        return implode("\n", $property->getValue($page->requires));
    }

    /**
     * Activity page of a managed, locked-down activity: core's activity header renders no manual completion control
     * (supported API activity_header::set_hidecompletion()), the plugin renders the explanatory state instead.
     */
    public function test_activity_page_shows_explanatory_state_instead_of_control(): void {
        $this->resetAfterTest();
        $this->build_env(['automanage' => false]);
        activity_manager::set_managed($this->pcm, true);
        $this->setUser($this->plearner);

        $page = $this->page($this->pcm);
        [$renderer, $html] = $this->header($page);
        $header = $page->activityheader->export_for_template($renderer);
        $this->assertStringNotContainsString('toggle-manual-completion', $this->header_controls($page, $header));
        $this->assertStringContainsString('role="note"', $html);
        $this->assertStringContainsString('data-region="local-lticompletionsync-managed"', $html);
        $this->assertStringContainsString(get_string('managednotice_todo', 'local_lticompletionsync'), $html);
        $this->assertStringContainsString(get_string(
            'managednotice_explain',
            'local_lticompletionsync',
            $this->pcm->name
        ), $html);
        $this->assertStringNotContainsString('toggle-manual-completion', $html);

        // Completed by the provider: the state says so.
        completion_adapter::apply($this->pcm, (int) $this->plearner->id, true);
        $page = $this->page(get_fast_modinfo($this->pcourse->id)->get_cm($this->pcm->id));
        [, $html] = $this->header($page);
        $this->assertStringContainsString(get_string('managednotice_done', 'local_lticompletionsync'), $html);
    }

    /**
     * Control group: unmanaged activity, a learner who may toggle (lockdown released), a teacher and an
     * administrator keep core's own header; no notice, no script.
     */
    public function test_core_header_kept_where_the_learner_can_toggle(): void {
        $this->resetAfterTest();
        $this->build_env(['automanage' => false]);

        $this->setUser($this->plearner);
        $page = $this->page($this->pcm);
        [$renderer, $html] = $this->header($page);
        $header = $page->activityheader->export_for_template($renderer);
        $details = \core_completion\cm_completion_details::get_instance($page->cm, (int) $this->plearner->id);
        $debug = json_encode((new \core_course\output\activity_completion($page->cm, $details))->export_for_template($renderer));
        $this->assertStringContainsString('toggle-manual-completion', $this->header_controls($page, $header) .
            $this->footer_controls($page, $renderer), $debug);
        $this->assertSame('', $html);

        activity_manager::set_managed($this->pcm, true);
        activity_manager::set_managed($this->pcm, false);
        $page = $this->page($this->pcm);
        [, $html] = $this->header($page);
        $this->assertSame('', $html);

        activity_manager::set_managed($this->pcm, true);
        $teacher = $this->getDataGenerator()->create_and_enrol($this->pcourse, 'editingteacher');
        foreach ([$teacher, get_admin()] as $user) {
            $this->setUser($user);
            $page = $this->page($this->pcm);
            [, $html] = $this->header($page);
            $this->assertSame('', $html);
            $this->assertStringNotContainsString('local-lticompletionsync-note', $this->inline_js($page));
        }
        $this->assertSame([], learner_view::locked_for_user((int) $this->pcourse->id, (int) $teacher->id));
    }

    /**
     * Course page: core offers no hook for the control (documented boundary, proved here: core's course format
     * output still exports the manual control); the plugin adds the accessible annotation script for exactly the
     * locked activities.
     */
    public function test_course_page_annotates_the_core_control(): void {
        $this->resetAfterTest();
        $this->build_env(['automanage' => false]);
        activity_manager::set_managed($this->pcm, true);
        $other = $this->getDataGenerator()->create_module('lti', ['course' => $this->pcourse->id,
            'completion' => COMPLETION_TRACKING_MANUAL]);
        $this->setUser($this->plearner);

        $page = $this->page(null);
        [$renderer, $html] = $this->header($page);
        $this->assertSame('', $html);
        $js = $this->inline_js($page);
        $this->assertStringContainsString('aria-disabled', $js);
        $this->assertStringContainsString('aria-describedby', $js);
        $this->assertStringContainsString('"' . $this->pcm->id . '"', $js);
        $this->assertStringNotContainsString('"' . $other->cmid . '"', $js);
        $this->assertStringContainsString(json_encode(get_string('managednotice_inline', 'local_lticompletionsync')), $js);

        // Boundary of core: the course format output still exports the manual control for this learner.
        $format = course_get_format($this->pcourse);
        $cm = get_fast_modinfo($this->pcourse->id, (int) $this->plearner->id)->get_cm($this->pcm->id);
        $section = $cm->get_section_info();
        $output = new \core_courseformat\output\local\content\cm\completion($format, $section, $cm);
        $data = $output->export_for_template($renderer);
        $this->assertNotNull($data);
        $this->assertTrue((bool) $data->ismanual);
        $this->assertTrue((bool) $data->istrackeduser);
    }

    /**
     * locked_for_user(): only managed activities with a written lockdown, only tracked learners without the toggle
     * capability, one query for the course.
     */
    public function test_locked_for_user(): void {
        global $DB;
        $this->resetAfterTest();
        $this->build_env(['automanage' => false]);
        $this->assertSame([], learner_view::locked_for_user((int) $this->pcourse->id, (int) $this->plearner->id));
        activity_manager::set_managed($this->pcm, true);
        $reads = $DB->perf_get_reads();
        $locked = learner_view::locked_for_user((int) $this->pcourse->id, (int) $this->plearner->id);
        $this->assertSame([(int) $this->pcm->id], array_keys($locked));
        $this->assertSame(0, $locked[(int) $this->pcm->id]['state']);
        $this->assertLessThan(25, $DB->perf_get_reads() - $reads);
        $this->assertSame([], learner_view::locked_for_user((int) $this->pcourse->id, (int) get_admin()->id));
        $this->assertSame([], learner_view::locked_for_user(SITEID, (int) $this->plearner->id));
    }
}
