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
     * @param array|null $adopt restore/duplicate only: lockdown of the source activity (lockedroleid, priorrecorded,
     *                          priorpermission), adopted by the deferred task when core copied its prohibition
     * @return \stdClass the row
     * @throws \moodle_exception when the activity cannot be managed
     */
    public static function set_managed(
        \cm_info $cm,
        bool $enabled,
        bool $deferlockdown = false,
        ?array $adopt = null
    ): \stdClass {
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
            $row = (object) ['cmid' => $cm->id, 'enabled' => 0, 'lockedroleid' => 0, 'priorrecorded' => 0,
                'priorpermission' => null, 'timecreated' => $now];
            $insert = (array) $row + ['usermodified' => $USER->id, 'timemodified' => $now];
            $row->id = $DB->insert_record(self::TABLE, (object) $insert);
        }
        $row->enabled = $enabled ? 1 : 0;
        if ($enabled && $con && $con->lockdown && (int) $row->lockedroleid === 0 && $deferlockdown) {
            $task = new \local_lticompletionsync\task\lockdown_task();
            $task->set_custom_data(['cmid' => (int) $cm->id, 'adopt' => $adopt]);
            \core\task\manager::queue_adhoc_task($task, true);
        } else if ($enabled && $con && $con->lockdown && (int) $row->lockedroleid === 0) {
            self::write_lockdown($cm, $row);
        } else if (!$enabled && (int) $row->lockedroleid > 0) {
            self::release($cm->context, $row);
        }
        $row->usermodified = $USER->id;
        $row->timemodified = $now;
        $DB->update_record(self::TABLE, $row);
        return $row;
    }

    /**
     * Write the lockdown and remember the override it replaces (in the row object, not saved).
     *
     * @param \cm_info $cm
     * @param \stdClass $row
     */
    private static function write_lockdown(\cm_info $cm, \stdClass $row): void {
        [$roleid, $prior] = completion_adapter::lockdown($cm);
        $row->lockedroleid = $roleid;
        $row->priorrecorded = $roleid > 0 ? 1 : 0;
        $row->priorpermission = $prior;
    }

    /**
     * Release the lockdown of a row (restores the prior override, see completion_adapter::release_lockdown()).
     *
     * @param \context $context module context
     * @param \stdClass $row (changed, not saved)
     * @return string RELEASE_* outcome
     */
    private static function release(\context $context, \stdClass $row): string {
        $outcome = completion_adapter::release_lockdown(
            $context,
            (int) $row->lockedroleid,
            !empty($row->priorrecorded),
            $row->priorpermission === null ? null : (int) $row->priorpermission
        );
        $row->lockedroleid = 0;
        $row->priorrecorded = 0;
        $row->priorpermission = null;
        return $outcome;
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
     * Restore and duplication ($adopt): core copies the permission overrides of the source activity into the new one
     * after the restore. When the source was locked down and its prohibition arrived in the copy, the copy is adopted
     * with the source's remembered prior override (so that a later release restores what the source had before),
     * instead of being mistaken for an administrator's prohibition.
     *
     * @param \cm_info $cm
     * @param \stdClass|null $con
     * @param array|null $adopt lockedroleid, priorrecorded, priorpermission of the source activity
     */
    public static function ensure_lockdown(\cm_info $cm, ?\stdClass $con = null, ?array $adopt = null): void {
        global $DB;
        $con = $con ?? self::connection_for($cm);
        $row = self::get($cm->id);
        if (!$con || !$con->lockdown || !$row || !$row->enabled || (int) $row->lockedroleid > 0) {
            return;
        }
        $roleid = completion_adapter::lockdown_roleid();
        if (
            $adopt && (int) ($adopt['lockedroleid'] ?? 0) === $roleid && $roleid > 0
            && completion_adapter::current_override($cm->context, $roleid) === CAP_PROHIBIT
        ) {
            $row->lockedroleid = $roleid;
            $row->priorrecorded = (int) !empty($adopt['priorrecorded']);
            $prior = $adopt['priorpermission'] ?? null;
            $row->priorpermission = $prior === null || $prior === '' ? null : (int) $prior;
        } else {
            self::write_lockdown($cm, $row);
        }
        $DB->update_record(self::TABLE, (object) [
            'id' => $row->id, 'lockedroleid' => $row->lockedroleid, 'priorrecorded' => $row->priorrecorded,
            'priorpermission' => $row->priorpermission, 'timemodified' => time(),
        ]);
    }

    /**
     * Lockdown state of an activity for display and diagnostics.
     *
     * @param \cm_info $cm
     * @return array applied (bool), users (int: tracked users who can still self-toggle), sample (int[]),
     *               override (int|null: current explicit override of the locked role), prior (int|null)
     */
    public static function lockdown_state(\cm_info $cm): array {
        $row = self::get($cm->id);
        $open = completion_adapter::users_who_can_toggle($cm);
        $roleid = $row && (int) $row->lockedroleid > 0 ? (int) $row->lockedroleid : completion_adapter::lockdown_roleid();
        return [
            'applied' => $row && (int) $row->lockedroleid > 0,
            'users' => $open['count'],
            'sample' => $open['sample'],
            'override' => $roleid > 0 ? completion_adapter::current_override($cm->context, $roleid) : null,
            'prior' => $row && !empty($row->priorrecorded) && $row->priorpermission !== null ? (int) $row->priorpermission : null,
        ];
    }

    /**
     * Release the learner lockdown of every activity this plugin manages or managed (uninstall).
     *
     * Rows with a lockdown restore the override that existed before it (see completion_adapter::release_lockdown()).
     * An enabled row without a lockdown yet (restore or duplication whose deferred lockdown task has not run) can hold
     * a prohibition that core copied from the source activity; that copy is removed (its prior state is unknown).
     * Overrides of other roles are never touched.
     *
     * @return array counts per outcome (restored, removed, kept_changed, none)
     */
    public static function release_all_lockdowns(): array {
        global $DB;
        $counts = [completion_adapter::RELEASE_RESTORED => 0, completion_adapter::RELEASE_REMOVED => 0,
            completion_adapter::RELEASE_KEPT => 0, completion_adapter::RELEASE_NONE => 0];
        $default = completion_adapter::lockdown_roleid();
        $rs = $DB->get_recordset(self::TABLE, null, 'id');
        foreach ($rs as $row) {
            $context = \context_module::instance((int) $row->cmid, IGNORE_MISSING);
            if (!$context) {
                continue;
            }
            if ((int) $row->lockedroleid > 0) {
                $counts[self::release($context, $row)]++;
                $DB->update_record(self::TABLE, (object) ['id' => $row->id, 'lockedroleid' => 0, 'priorrecorded' => 0,
                    'priorpermission' => null]);
                continue;
            }
            if ($row->enabled && $default > 0 && completion_adapter::current_override($context, $default) === CAP_PROHIBIT) {
                // Deferred lockdown not yet written: the prohibition is the copy of the source's lockdown.
                $counts[completion_adapter::release_lockdown($context, $default, false, null)]++;
            }
        }
        $rs->close();
        return $counts;
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
