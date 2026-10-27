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

namespace local_lticompletionsync\local\sender;

use local_lticompletionsync\local\connection;
use local_lticompletionsync\local\journal;

/**
 * Tool side reconciliation: event path, periodic delta sweep and controllable full reconcile.
 *
 * Events are only a fast path. The sweep catches changes without an event (revoked course completion after a reset,
 * missed observer) by re-reading the provider state of every reported target and of recently changed completions;
 * the full reconcile walks every user of every resource link of a connection.
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reconciler {
    /**
     * Event path: a completion changed for a user in a context.
     *
     * @param int $userid
     * @param \context $context module or course context
     * @param string $trigger
     * @return int number of new versions
     */
    public static function on_change(int $userid, \context $context, string $trigger = 'event'): int {
        $count = 0;
        foreach (source::targets_for($userid, $context) as $target) {
            $count += self::check_target($target, $trigger) ? 1 : 0;
        }
        return $count;
    }

    /**
     * Compare one target with the provider state and queue a new version when it differs.
     *
     * @param \stdClass $target
     * @param string $trigger
     * @return bool true when a new version was queued
     */
    public static function check_target(\stdClass $target, string $trigger): bool {
        $state = source::provider_state($target->sourcekind, $target->sourceid, $target->userid);
        if ($state === null) {
            return false;
        }
        $row = outbox::record_state($target, $state, $trigger);
        if ($row === null) {
            return false;
        }
        if ($trigger !== 'event') {
            journal::record(
                'reconciled',
                $row->correlationid,
                (int) $row->connectionid,
                (int) $row->userid,
                outbox::course_of($row),
                $row->sourcekind === source::KIND_CM ? (int) $row->sourceid : 0,
                (int) $row->version,
                $trigger
            );
        }
        outbox::queue_delivery((int) $row->id);
        return true;
    }

    /**
     * Delta sweep: every target already reported (revocations without an event) and every completion changed since
     * the last run.
     *
     * @param int $since
     * @return int number of new versions
     */
    public static function sweep(int $since): int {
        global $DB;
        $count = 0;
        $rows = $DB->get_recordset(outbox::TABLE, null, 'id ASC');
        foreach ($rows as $row) {
            $con = connection::get((int) $row->connectionid);
            if (!$con || !$con->enabled) {
                continue;
            }
            $target = (object) [
                'connection' => $con,
                'resourcelinkid' => (int) $row->resourcelinkid,
                'toolid' => (int) $row->toolid,
                'userid' => (int) $row->userid,
                'sourcekind' => $row->sourcekind,
                'sourceid' => (int) $row->sourceid,
            ];
            $count += self::check_target($target, 'sweep') ? 1 : 0;
        }
        $rows->close();

        // Completions changed since the last run in published activities and courses.
        $sql = "SELECT DISTINCT cmc.userid, ctx.id AS contextid
                  FROM {course_modules_completion} cmc
                  JOIN {context} ctx ON ctx.instanceid = cmc.coursemoduleid AND ctx.contextlevel = :modlevel
                  JOIN {enrol_lti_tools} t ON t.contextid = ctx.id
                 WHERE cmc.timemodified >= :since
                 UNION
                SELECT DISTINCT cc.userid, ctx.id AS contextid
                  FROM {course_completions} cc
                  JOIN {context} ctx ON ctx.instanceid = cc.course AND ctx.contextlevel = :courselevel
                  JOIN {enrol_lti_tools} t ON t.contextid = ctx.id
                 WHERE cc.timecompleted >= :since2";
        $changes = $DB->get_recordset_sql($sql, [
            'modlevel' => CONTEXT_MODULE, 'since' => $since, 'courselevel' => CONTEXT_COURSE, 'since2' => $since,
        ]);
        foreach ($changes as $change) {
            $context = \context::instance_by_id((int) $change->contextid, IGNORE_MISSING);
            if ($context) {
                $count += self::on_change((int) $change->userid, $context, 'sweep');
            }
        }
        $changes->close();
        return $count;
    }

    /**
     * Full reconcile of one connection or of all enabled sender connections.
     *
     * @param int $connectionid 0 for all
     * @return int number of new versions
     */
    public static function full(int $connectionid = 0): int {
        $count = 0;
        foreach (connection::all() as $con) {
            if ($con->role !== connection::ROLE_SENDER || !$con->enabled) {
                continue;
            }
            if ($connectionid && (int) $con->id !== $connectionid) {
                continue;
            }
            foreach (source::targets_of_connection($con) as $target) {
                $count += self::check_target($target, 'full') ? 1 : 0;
            }
        }
        return $count;
    }
}
