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

namespace local_lticompletionsync\local\receiver;

use local_lticompletionsync\local\completion_adapter;

/**
 * Learner view of provider-managed activities: explanatory state instead of a dead-end "Mark as done" control.
 *
 * The security measure is the capability prohibition (completion_adapter::lockdown()); this class only shapes what a
 * locked-down learner sees. Moodle core 4.5-5.3 has exactly one supported, plugin-reachable switch for the manual
 * completion control: activity_header::set_hidecompletion() on the activity page, which a callback of the hook
 * \core\hook\output\before_http_headers can call before the theme layout exports the header. The course page
 * (core_courseformat\output\local\content\cm\completion, also used by the 5.x activities overview) and, on 5.3, the
 * linear navigation footer (core_courseformat\output\local\linearnavigation\footer_content) render the control
 * without a hook or callback
 * (see docs/DOCUMENTATION.md, CS-02). There the plugin adds an explanatory note next to the control and marks it
 * aria-disabled (progressive enhancement; the control itself only works with JavaScript, the server refuses the toggle
 * either way).
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class learner_view {
    /** @var array|null Notice prepared for the current page by before_http_headers (cmid, state, toolname). */
    public static $pending = null;

    /**
     * Managed, locked-down activities of a course for which a user lacks the toggle capability.
     *
     * One query for the managed rows of the course; capability checks only for those activities.
     *
     * @param int $courseid
     * @param int $userid
     * @return array cmid => ['name' => string, 'state' => 0|1]
     */
    public static function locked_for_user(int $courseid, int $userid): array {
        global $DB, $CFG;
        if ($courseid <= SITEID || $userid <= 0 || isguestuser($userid) || is_siteadmin($userid)) {
            return [];
        }
        $cmids = $DB->get_fieldset_sql(
            "SELECT a.cmid
               FROM {" . activity_manager::TABLE . "} a
               JOIN {course_modules} cm ON cm.id = a.cmid
              WHERE cm.course = :courseid AND a.enabled = 1 AND a.lockedroleid > 0",
            ['courseid' => $courseid]
        );
        if (!$cmids) {
            return [];
        }
        require_once($CFG->libdir . '/completionlib.php');
        $modinfo = get_fast_modinfo($courseid, $userid);
        $completion = new \completion_info($modinfo->get_course());
        if (!$completion->is_enabled() || !$completion->is_tracked_user($userid)) {
            return [];
        }
        $result = [];
        foreach ($cmids as $cmid) {
            $cm = $modinfo->cms[(int) $cmid] ?? null;
            if (!$cm || !$cm->uservisible || (int) $cm->completion !== COMPLETION_TRACKING_MANUAL) {
                continue;
            }
            if (has_capability(completion_adapter::TOGGLE_CAPABILITY, $cm->context, $userid)) {
                continue;
            }
            $data = $completion->get_data($cm, true, $userid);
            $result[(int) $cm->id] = [
                'name' => format_string($cm->name, true, ['context' => $cm->context, 'escape' => false]),
                'state' => completion_adapter::is_complete_state((int) $data->completionstate) ? 1 : 0,
            ];
        }
        return $result;
    }

    /**
     * Prepare the page before the header is rendered (callback of before_http_headers).
     *
     * @param \moodle_page $page
     */
    public static function prepare(\moodle_page $page): void {
        global $USER;
        self::$pending = null;
        if (during_initial_install() || !isloggedin() || isguestuser()) {
            return;
        }
        $pagetype = (string) $page->pagetype;
        $onactivity = strpos($pagetype, 'mod-lti-view') === 0;
        // Course pages (course-view-*, the 5.x activities overview course-overview, ...) render the control in
        // course format output classes without a hook.
        $oncourse = strpos($pagetype, 'course-') === 0;
        if (!$onactivity && !$oncourse) {
            return;
        }
        $course = $page->course;
        if (!$course || (int) $course->id <= SITEID) {
            return;
        }
        $locked = self::locked_for_user((int) $course->id, (int) $USER->id);
        if (!$locked) {
            return;
        }
        if ($onactivity) {
            $cm = $page->cm;
            if (!$cm || !isset($locked[(int) $cm->id])) {
                return;
            }
            // Supported API of the activity header: the header does not render the manual completion control; the
            // explanatory state is added by after_http_headers right below the header instead.
            $page->activityheader->set_hidecompletion(true);
            self::$pending = ['cmid' => (int) $cm->id] + $locked[(int) $cm->id];
            // The linear navigation footer (5.3) renders its own control without a hook: annotate it.
            $locked = [(int) $cm->id => $locked[(int) $cm->id]];
        }
        self::annotate_controls($page, $locked);
    }

    /**
     * Explanatory note next to every remaining manual completion control of a locked activity (progressive
     * enhancement, see the class comment). The control stays visible; it is marked aria-disabled, described by the
     * note and its activation is consumed before core's toggle handler sees it.
     *
     * @param \moodle_page $page
     * @param array $locked cmid => [name, state]
     */
    private static function annotate_controls(\moodle_page $page, array $locked): void {
        $config = [
            'note' => get_string('managednotice_inline', 'local_lticompletionsync'),
            'cms' => [],
        ];
        foreach ($locked as $cmid => $info) {
            $config['cms'][(string) $cmid] = get_string('managednotice_aria', 'local_lticompletionsync', $info['name']);
        }
        $json = json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $page->requires->js_amd_inline(<<<JS
(function(cfg) {
    var selector = 'button[data-action="toggle-manual-completion"]';
    var counter = 0;
    var apply = function() {
        document.querySelectorAll(selector).forEach(function(button) {
            var cmid = button.getAttribute('data-cmid');
            if (!cfg.cms[cmid] || button.getAttribute('data-lticompletionsync') === 'managed') {
                return;
            }
            counter++;
            var note = document.createElement('span');
            note.id = 'local-lticompletionsync-note-' + cmid + '-' + counter;
            note.className = 'local-lticompletionsync-note small text-muted ml-1 ms-1';
            note.textContent = cfg.note;
            button.insertAdjacentElement('afterend', note);
            button.setAttribute('data-lticompletionsync', 'managed');
            button.setAttribute('aria-disabled', 'true');
            button.setAttribute('aria-describedby', note.id);
            button.setAttribute('title', cfg.cms[cmid]);
        });
    };
    document.addEventListener('click', function(e) {
        var button = e.target.closest ? e.target.closest(selector) : null;
        if (button && cfg.cms[button.getAttribute('data-cmid')]) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);
    apply();
    new MutationObserver(apply).observe(document.body, {childList: true, subtree: true});
})($json);
JS
        );
    }

    /**
     * The explanatory state rendered right below the activity header (callback of after_http_headers).
     *
     * @param \renderer_base $output
     * @return string HTML ('' when nothing is pending)
     */
    public static function notice_html(\renderer_base $output): string {
        if (self::$pending === null) {
            return '';
        }
        $info = self::$pending;
        self::$pending = null;
        return $output->render_from_template('local_lticompletionsync/managed_notice', [
            'cmid' => $info['cmid'],
            'done' => (bool) $info['state'],
            'activityname' => $info['name'],
        ]);
    }
}
