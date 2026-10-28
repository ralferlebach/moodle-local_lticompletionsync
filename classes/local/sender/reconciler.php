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
 * Bounded resources (CS-04): every path works in batches of source::BATCH targets with a constant number of queries
 * per batch for the unchanged targets (relation rows, provider states, existing outbox rows); only targets whose state
 * changed cost per-target writes. The sweep and the reconcile queue one deduplicated drain task instead of one ad hoc
 * task per new version.
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
        return self::check_targets(source::targets_for($userid, $context), $trigger);
    }

    /**
     * Compare one target with the provider state and queue a new version when it differs.
     *
     * @param \stdClass $target
     * @param string $trigger
     * @return bool true when a new version was queued
     */
    public static function check_target(\stdClass $target, string $trigger): bool {
        return self::check_targets([$target], $trigger) > 0;
    }

    /**
     * Compare a batch of targets with their provider states and record new versions where they differ.
     *
     * Queries for a batch: one per source for the provider states, one per connection for the existing outbox rows;
     * writes only for changed targets. The event path queues one ad hoc delivery per new version (fast path); every
     * other trigger queues a single deduplicated drain task.
     *
     * @param \stdClass[] $targets
     * @param string $trigger event, sweep, full, resync
     * @param bool $force record a new version even when the state did not change (force resync)
     * @return int number of new versions
     */
    public static function check_targets(array $targets, string $trigger, bool $force = false): int {
        if (!$targets) {
            return 0;
        }
        $states = source::provider_states($targets);
        $existing = outbox::rows_for_targets($targets);
        $count = 0;
        foreach ($targets as $target) {
            $state = $states[source::state_key($target)] ?? null;
            if ($state === null) {
                continue;
            }
            $current = $existing[outbox::target_key($target)] ?? null;
            if (!$force && (($current && (int) $current->desiredstate === $state) || (!$current && $state === 0))) {
                // Unchanged, or nothing was ever reported and there is nothing to revoke.
                continue;
            }
            $row = outbox::record_state($target, $state, $trigger, $force);
            if ($row === null) {
                continue;
            }
            $count++;
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
            if ($trigger === 'event') {
                outbox::queue_delivery((int) $row->id);
            }
        }
        if ($count && $trigger !== 'event') {
            outbox::queue_drain();
        }
        return $count;
    }

    /**
     * Delta sweep: every target already reported (revocations without an event) and every completion changed since
     * the last run. Keyset batches; memory bounded by the batch size.
     *
     * @param int $since
     * @param int $batch
     * @return int number of new versions
     */
    public static function sweep(int $since, int $batch = source::BATCH): int {
        global $DB;
        $count = 0;
        $connections = [];
        $last = 0;
        while (true) {
            $rows = $DB->get_records_select(outbox::TABLE, 'id > :last', ['last' => $last], 'id ASC', '*', 0, $batch);
            if (!$rows) {
                break;
            }
            $last = (int) end($rows)->id;
            $targets = [];
            foreach ($rows as $row) {
                $cid = (int) $row->connectionid;
                if (!array_key_exists($cid, $connections)) {
                    $connections[$cid] = connection::get($cid);
                }
                $con = $connections[$cid];
                if (!$con || !$con->enabled || $con->role !== connection::ROLE_SENDER) {
                    continue;
                }
                $targets[] = (object) [
                    'connection' => $con,
                    'resourcelinkid' => (int) $row->resourcelinkid,
                    'toolid' => (int) $row->toolid,
                    'userid' => (int) $row->userid,
                    'sourcekind' => $row->sourcekind,
                    'sourceid' => (int) $row->sourceid,
                ];
            }
            $count += self::check_targets($targets, 'sweep');
            if (count($rows) < $batch) {
                break;
            }
        }

        // Completions changed since the last run in published activities and courses, grouped by context.
        $sql = "SELECT DISTINCT ctx.id AS contextid, cmc.userid
                  FROM {course_modules_completion} cmc
                  JOIN {context} ctx ON ctx.instanceid = cmc.coursemoduleid AND ctx.contextlevel = :modlevel
                  JOIN {enrol_lti_tools} t ON t.contextid = ctx.id
                 WHERE cmc.timemodified >= :since
                 UNION
                SELECT DISTINCT ctx.id AS contextid, cc.userid
                  FROM {course_completions} cc
                  JOIN {context} ctx ON ctx.instanceid = cc.course AND ctx.contextlevel = :courselevel
                  JOIN {enrol_lti_tools} t ON t.contextid = ctx.id
                 WHERE cc.timecompleted >= :since2
              ORDER BY contextid, userid";
        $changes = $DB->get_recordset_sql($sql, [
            'modlevel' => CONTEXT_MODULE, 'since' => $since, 'courselevel' => CONTEXT_COURSE, 'since2' => $since,
        ]);
        $contextid = 0;
        $userids = [];
        $flush = function () use (&$contextid, &$userids, &$count) {
            if ($contextid && $userids) {
                $context = \context::instance_by_id($contextid, IGNORE_MISSING);
                if ($context) {
                    $count += self::check_targets(source::targets_for_users($context, $userids), 'sweep');
                }
            }
            $userids = [];
        };
        foreach ($changes as $change) {
            if ((int) $change->contextid !== $contextid || count($userids) >= $batch) {
                $flush();
                $contextid = (int) $change->contextid;
            }
            $userids[] = (int) $change->userid;
        }
        $flush();
        $changes->close();
        return $count;
    }

    /**
     * Full reconcile of one connection or of all enabled sender connections (keyset batches).
     *
     * @param int $connectionid 0 for all
     * @param bool $force record a new version for every target even when unchanged (administrator "force resync")
     * @return int number of new versions
     */
    public static function full(int $connectionid = 0, bool $force = false): int {
        $count = 0;
        foreach (connection::all() as $con) {
            if ($con->role !== connection::ROLE_SENDER || !$con->enabled) {
                continue;
            }
            if ($connectionid && (int) $con->id !== $connectionid) {
                continue;
            }
            source::each_target_batch($con, function (array $targets) use (&$count, $force) {
                $count += self::check_targets($targets, $force ? 'resync' : 'full', $force);
            });
        }
        return $count;
    }
}
