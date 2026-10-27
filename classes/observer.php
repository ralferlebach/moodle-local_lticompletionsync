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

use local_lticompletionsync\local\receiver\activity_manager;
use local_lticompletionsync\local\receiver\processor;
use local_lticompletionsync\local\sender\reconciler;

/**
 * Event observers of both roles.
 *
 * Observers never perform network requests: the sender side only records a new desired state and queues an ad hoc
 * delivery; the receiver side only detects local changes of managed activities.
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Activity completion changed (both roles).
     *
     * @param \core\event\course_module_completion_updated $event
     */
    public static function cm_completion_updated(\core\event\course_module_completion_updated $event): void {
        $userid = (int) $event->relateduserid;
        $context = $event->get_context();
        if ($userid <= 0 || !$context || $context->contextlevel != CONTEXT_MODULE) {
            return;
        }
        // Sender: the activity may be a published LTI resource.
        reconciler::on_change($userid, $context);
        // Receiver: a change of a managed activity that this plugin did not make.
        if (!processor::$applying) {
            processor::local_change((int) $context->instanceid, $userid);
        }
    }

    /**
     * Course completed (sender role: published courses report course completion).
     *
     * @param \core\event\course_completed $event
     */
    public static function course_completed(\core\event\course_completed $event): void {
        $userid = (int) $event->relateduserid;
        if ($userid > 0) {
            reconciler::on_change($userid, \context_course::instance($event->courseid));
        }
    }

    /**
     * Activity deleted: forget its receiver data.
     *
     * @param \core\event\course_module_deleted $event
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        activity_manager::forget((int) $event->objectid);
    }

    /**
     * User deleted: delete the state rows and journal entries of the user.
     *
     * @param \core\event\user_deleted $event
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        global $DB;
        $userid = (int) $event->objectid;
        $DB->delete_records('local_lticompletionsync_out', ['userid' => $userid]);
        $DB->delete_records('local_lticompletionsync_rcv', ['userid' => $userid]);
        $DB->delete_records('local_lticompletionsync_log', ['userid' => $userid]);
    }
}
