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

/**
 * Navigation callbacks.
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add the "Completion sync" page to the settings of an external tool activity.
 *
 * @param settings_navigation $settingsnav
 * @param context $context
 */
function local_lticompletionsync_extend_settings_navigation($settingsnav, $context) {
    // Not empty($PAGE->cm): moodle_page has a magic getter but no __isset, so empty() is always true.
    $cm = $settingsnav->get_page()->cm;
    if (!$context || $context->contextlevel != CONTEXT_MODULE || !$cm || $cm->modname !== 'lti') {
        return;
    }
    if (!has_capability('local/lticompletionsync:manageactivity', $context)) {
        return;
    }
    $modulesettings = $settingsnav->find('modulesettings', navigation_node::TYPE_SETTING);
    if (!$modulesettings) {
        return;
    }
    $modulesettings->add(
        get_string('activitypage', 'local_lticompletionsync'),
        new moodle_url('/local/lticompletionsync/activity.php', ['id' => $cm->id]),
        navigation_node::TYPE_SETTING,
        null,
        'local_lticompletionsync_activity'
    );
}

/**
 * Add the course status page to the course navigation.
 *
 * @param navigation_node $navigation
 * @param stdClass $course
 * @param context_course $context
 */
function local_lticompletionsync_extend_navigation_course($navigation, $course, $context) {
    if (!has_capability('local/lticompletionsync:viewstatus', $context)) {
        return;
    }
    $navigation->add(
        get_string('statuspage', 'local_lticompletionsync'),
        new moodle_url('/local/lticompletionsync/status.php', ['id' => $course->id]),
        navigation_node::TYPE_SETTING,
        null,
        'local_lticompletionsync_status'
    );
}
