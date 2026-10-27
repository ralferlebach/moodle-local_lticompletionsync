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
use local_lticompletionsync\local\connection;

/**
 * Receiver side: which mod_lti activities have their completion managed by the provider, and their lockdown.
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_manager {
    /** @var string Table. */
    public const TABLE = 'local_lticompletionsync_act';

    /**
     * The activity row.
     *
     * @param int $cmid
     * @return \stdClass|null
     */
    public static function get(int $cmid): ?\stdClass {
        global $DB;
        $row = $DB->get_record(self::TABLE, ['cmid' => $cmid]);
        return $row ?: null;
    }

    /**
     * The receiver connection responsible for an LTI activity (by its tool type).
     *
     * @param \cm_info $cm
     * @return \stdClass|null
     */
    public static function connection_for(\cm_info $cm): ?\stdClass {
        global $DB;
        if ($cm->modname !== 'lti') {
            return null;
        }
        $typeid = (int) $DB->get_field('lti', 'typeid', ['id' => $cm->instance]);
        if ($typeid <= 0) {
            return null;
        }
        $row = $DB->get_record(connection::TABLE, ['role' => connection::ROLE_RECEIVER, 'ltitypeid' => $typeid]);
        return $row ?: null;
    }

    /**
     * Whether the provider manages the activity's completion.
     *
     * @param \cm_info $cm
     * @param \stdClass|null $con receiver connection (looked up when null)
     * @return bool
     */
    public static function is_managed(\cm_info $cm, ?\stdClass $con = null): bool {
        $con = $con ?? self::connection_for($cm);
        if (!$con || !$con->enabled) {
            return false;
        }
        $row = self::get($cm->id);
        if ($row) {
            return (bool) $row->enabled;
        }
        return (bool) $con->automanage;
    }

    /**
     * Switch provider management of an activity on or off (teacher or administrator action).
     *
     * @param \cm_info $cm
     * @param bool $enabled
     * @param bool $deferlockdown write the lockdown later by an ad hoc task (used during restore and duplication,
     *                            where core copies the permission overrides itself after the restore)
     * @return \stdClass the row
     * @throws \moodle_exception when the activity cannot be managed
     */
    public static function set_managed(\cm_info $cm, bool $enabled, bool $deferlockdown = false): \stdClass {
        global $DB, $USER;
        $con = self::connection_for($cm);
        if ($enabled) {
            $reason = completion_adapter::unsupported_reason($cm);
            if ($reason !== '') {
                throw new \moodle_exception('unsupported_' . $reason, 'local_lticompletionsync');
            }
            if (!$con) {
                throw new \moodle_exception('error_noreceiver', 'local_lticompletionsync');
            }
        }
        $now = time();
        $row = self::get($cm->id);
        if (!$row) {
            $row = (object) ['cmid' => $cm->id, 'enabled' => 0, 'lockedroleid' => 0, 'timecreated' => $now];
            $insert = (array) $row + ['usermodified' => $USER->id, 'timemodified' => $now];
            $row->id = $DB->insert_record(self::TABLE, (object) $insert);
        }
        $row->enabled = $enabled ? 1 : 0;
        if ($enabled && $con && $con->lockdown && (int) $row->lockedroleid === 0 && $deferlockdown) {
            $task = new \local_lticompletionsync\task\lockdown_task();
            $task->set_custom_data(['cmid' => (int) $cm->id]);
            \core\task\manager::queue_adhoc_task($task, true);
        } else if ($enabled && $con && $con->lockdown && (int) $row->lockedroleid === 0) {
            $row->lockedroleid = completion_adapter::lockdown($cm);
        } else if (!$enabled && (int) $row->lockedroleid > 0) {
            completion_adapter::release_lockdown($cm, (int) $row->lockedroleid);
            $row->lockedroleid = 0;
        }
        $row->usermodified = $USER->id;
        $row->timemodified = $now;
        $DB->update_record(self::TABLE, $row);
        return $row;
    }

    /**
     * Make sure an activity named in a message is managed; with automatic management create its row (and lockdown).
     *
     * @param \cm_info $cm
     * @param \stdClass $con
     * @throws rejection activity_not_managed
     */
    public static function require_managed(\cm_info $cm, \stdClass $con): void {
        $row = self::get($cm->id);
        if ($row && $row->enabled) {
            self::ensure_lockdown($cm, $con);
            return;
        }
        if (!$row && $con->automanage) {
            self::set_managed($cm, true);
            return;
        }
        throw new rejection('activity_not_managed');
    }

    /**
     * Write the lockdown of a managed activity if the connection wants it and it was never written.
     *
     * A lockdown that this plugin wrote and somebody removed later is not written again (an administrator decision);
     * it shows up in the lockdown state instead.
     *
     * @param \cm_info $cm
     * @param \stdClass|null $con
     */
    public static function ensure_lockdown(\cm_info $cm, ?\stdClass $con = null): void {
        global $DB;
        $con = $con ?? self::connection_for($cm);
        $row = self::get($cm->id);
        if (!$con || !$con->lockdown || !$row || !$row->enabled || (int) $row->lockedroleid > 0) {
            return;
        }
        $DB->update_record(self::TABLE, (object) [
            'id' => $row->id, 'lockedroleid' => completion_adapter::lockdown($cm), 'timemodified' => time(),
        ]);
    }

    /**
     * Lockdown state of an activity for display and diagnostics.
     *
     * @param \cm_info $cm
     * @return array applied (bool), users (int: tracked users who can still self-toggle)
     */
    public static function lockdown_state(\cm_info $cm): array {
        $row = self::get($cm->id);
        return [
            'applied' => $row && (int) $row->lockedroleid > 0,
            'users' => count(completion_adapter::users_who_can_toggle($cm)),
        ];
    }

    /**
     * Remove the learner lockdown of every activity this plugin manages or managed (uninstall).
     *
     * Covers lockdowns written by this plugin (lockedroleid) and prohibitions that core copied when a managed activity
     * was duplicated before the deferred lockdown task ran (lockedroleid still 0). Only a CAP_PROHIBIT of the
     * authenticated-user role in the activity's own context is removed; other overrides stay untouched.
     *
     * @return int number of removed overrides
     */
    public static function release_all_lockdowns(): int {
        global $DB;
        $count = 0;
        $roleids = array_filter([completion_adapter::lockdown_roleid()]);
        foreach ($DB->get_records(self::TABLE) as $row) {
            if ((int) $row->lockedroleid > 0) {
                $roleids[] = (int) $row->lockedroleid;
            }
            $context = \context_module::instance((int) $row->cmid, IGNORE_MISSING);
            if (!$context) {
                continue;
            }
            foreach (array_unique($roleids) as $roleid) {
                $exists = $DB->record_exists('role_capabilities', ['contextid' => $context->id, 'roleid' => $roleid,
                    'capability' => completion_adapter::TOGGLE_CAPABILITY, 'permission' => CAP_PROHIBIT]);
                if ($exists) {
                    unassign_capability(completion_adapter::TOGGLE_CAPABILITY, $roleid, $context->id);
                    $context->mark_dirty();
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * Remove all receiver data of a deleted activity.
     *
     * @param int $cmid
     */
    public static function forget(int $cmid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['cmid' => $cmid]);
        $DB->delete_records('local_lticompletionsync_rcv', ['cmid' => $cmid]);
    }
}
