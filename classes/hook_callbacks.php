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

use local_lticompletionsync\local\merge_integration;
use local_lticompletionsync\local\receiver\learner_view;

/**
 * Hook callbacks (db/hooks.php).
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Before the page header is rendered: explanatory state instead of the manual completion control (CS-02).
     *
     * @param \core\hook\output\before_http_headers $hook
     */
    public static function before_http_headers(\core\hook\output\before_http_headers $hook): void {
        try {
            learner_view::prepare($hook->renderer->get_page());
        } catch (\Throwable $e) {
            // Never break a page because of the explanatory state; the server-side lockdown is unaffected.
            learner_view::$pending = null;
            debugging('local_lticompletionsync: learner view not prepared: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * After the page header: the explanatory state right below the activity header.
     *
     * @param \core\hook\output\after_http_headers $hook
     */
    public static function after_http_headers(\core\hook\output\after_http_headers $hook): void {
        $hook->add_html(learner_view::notice_html($hook->renderer));
    }

    /**
     * tool_mergeusers: take the plugin's user tables out of the generic processing.
     *
     * @param object $hook \tool_mergeusers\hook\add_settings_before_merging (typed object: no dependency)
     */
    public static function mergeusers_add_settings(object $hook): void {
        merge_integration::add_settings_before_merging($hook);
    }

    /**
     * tool_mergeusers: move the plugin's rows to the kept user inside the merge transaction.
     *
     * @param object $hook \tool_mergeusers\hook\after_merged_all_tables (typed object: no dependency)
     */
    public static function mergeusers_after_merged_all_tables(object $hook): void {
        merge_integration::after_merged_all_tables($hook);
    }
}
