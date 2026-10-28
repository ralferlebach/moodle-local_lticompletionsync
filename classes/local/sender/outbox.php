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

use local_lticompletionsync\local\config;
use local_lticompletionsync\local\journal;

/**
 * Tool side outbox: one row per (connection, resource link, user) holding the desired provider state.
 *
 * State based, not event based: a change raises a monotonic version and a strictly increasing state time; a
 * revocation is a new version with state 0, never a deletion. Sending the same version again is idempotent at the
 * receiver.
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class outbox {
    /** @var string Table. */
    public const TABLE = 'local_lticompletionsync_out';

    /** @var string Waiting for the first delivery of the current version. */
    public const STATUS_PENDING = 'pending';
    /** @var string Delivered without end-to-end confirmation (tool-only AGS level). */
    public const STATUS_SENT = 'sent';
    /** @var string Receiver confirmed "applied" for the current version. */
    public const STATUS_ACKED = 'acked';
    /** @var string Delivery failed, retry scheduled. */
    public const STATUS_RETRY = 'retry';
    /** @var string Permanently refused or attempts exhausted. */
    public const STATUS_DEAD = 'dead';
    /** @var string Waiting for an administrator or a local decision (no partner plugin, local conflict). */
    public const STATUS_BLOCKED = 'blocked';

    /**
     * Record the current provider state of a target. Creates a new version when the state differs.
     *
     * @param \stdClass $target from source::targets_for()
     * @param int $state 1 or 0
     * @param string $trigger short code (observer, sweep, reconcile)
     * @param bool $force raise the version even when the state is unchanged (administrator "force resync")
     * @return \stdClass|null the row when a new version was created, null when nothing changed
     */
    public static function record_state(\stdClass $target, int $state, string $trigger, bool $force = false): ?\stdClass {
        global $DB;
        $key = [
            'connectionid' => (int) $target->connection->id,
            'resourcelinkid' => $target->resourcelinkid,
            'userid' => $target->userid,
        ];
        for ($try = 0; $try < 3; $try++) {
            $row = $DB->get_record(self::TABLE, $key);
            $now = time();
            if (!$row) {
                if ($state === 0) {
                    // Nothing was ever reported: there is nothing to revoke.
                    return null;
                }
                $row = (object) ($key + [
                    'toolid' => $target->toolid,
                    'sourcekind' => $target->sourcekind,
                    'sourceid' => $target->sourceid,
                    'desiredstate' => $state,
                    'version' => 1,
                    'statetime' => $now,
                    'correlationid' => \core\uuid::generate(),
                    'status' => self::STATUS_PENDING,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
                try {
                    $id = $DB->insert_record(self::TABLE, $row);
                } catch (\dml_write_exception $e) {
                    continue; // Created in parallel: re-read and compare.
                }
                $row = $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
            } else {
                if ((int) $row->desiredstate === $state && !$force) {
                    return null;
                }
                $old = (int) $row->version;
                $fields = [
                    'desiredstate' => $state,
                    'version' => $old + 1,
                    'statetime' => max($now, (int) $row->statetime + 1),
                    'correlationid' => \core\uuid::generate(),
                    'status' => self::STATUS_PENDING,
                    'attempts' => 0,
                    'nextattempt' => 0,
                    'lasterror' => null,
                    'timemodified' => $now,
                ];
                $sets = [];
                $params = ['id' => $row->id, 'oldversion' => $old];
                foreach ($fields as $name => $value) {
                    $sets[] = $value === null ? "$name = NULL" : "$name = :$name";
                    if ($value !== null) {
                        $params[$name] = $value;
                    }
                }
                // Optimistic concurrency: only the request that still sees the old version raises it.
                $DB->execute('UPDATE {' . self::TABLE . '} SET ' . implode(', ', $sets) .
                    ' WHERE id = :id AND version = :oldversion', $params);
                $row = $DB->get_record(self::TABLE, ['id' => $row->id]);
                $mine = (int) $row->version === $old + 1 && (int) $row->desiredstate === $state;
                if (!$mine || $row->status !== self::STATUS_PENDING) {
                    continue;
                }
            }
            $courseid = self::course_of($row);
            $cmid = $row->sourcekind === source::KIND_CM ? (int) $row->sourceid : 0;
            journal::record(
                'source_detected',
                $row->correlationid,
                (int) $row->connectionid,
                (int) $row->userid,
                $courseid,
                $cmid,
                (int) $row->version,
                $trigger . ':' . $row->sourcekind
            );
            journal::record(
                'queued',
                $row->correlationid,
                (int) $row->connectionid,
                (int) $row->userid,
                $courseid,
                $cmid,
                (int) $row->version,
                'state=' . $state
            );
            return $row;
        }
        return null;
    }

    /**
     * Key of a target in the result of rows_for_targets().
     *
     * @param \stdClass $target
     * @return string
     */
    public static function target_key(\stdClass $target): string {
        $cid = isset($target->connection) ? (int) $target->connection->id : (int) $target->connectionid;
        return $cid . '/' . (int) $target->resourcelinkid . '/' . (int) $target->userid;
    }

    /**
     * Existing outbox rows of a batch of targets (one query per connection and batch).
     *
     * @param \stdClass[] $targets
     * @return array target_key => row
     */
    public static function rows_for_targets(array $targets): array {
        global $DB;
        $byconnection = [];
        $links = [];
        foreach ($targets as $target) {
            $cid = (int) $target->connection->id;
            $byconnection[$cid][(int) $target->userid] = true;
            $links[$cid][(int) $target->resourcelinkid] = true;
        }
        $rows = [];
        foreach ($byconnection as $cid => $users) {
            // Prefix (connectionid, resourcelinkid) of the unique index "target", then the user ids.
            [$rlsql, $rlparams] = $DB->get_in_or_equal(array_keys($links[$cid]), SQL_PARAMS_NAMED, 'rl');
            foreach (array_chunk(array_keys($users), source::BATCH) as $chunk) {
                [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
                $found = $DB->get_records_select(
                    self::TABLE,
                    "connectionid = :cid AND resourcelinkid $rlsql AND userid $insql",
                    ['cid' => $cid] + $rlparams + $params
                );
                foreach ($found as $row) {
                    $rows[self::target_key($row)] = $row;
                }
            }
        }
        return $rows;
    }

    /**
     * Course of the source of a row (for journal and status pages; cached per run).
     *
     * @param \stdClass $row
     * @return int
     */
    public static function course_of(\stdClass $row): int {
        if ($row->sourcekind === source::KIND_COURSE) {
            return (int) $row->sourceid;
        }
        return source::course_of_cm((int) $row->sourceid);
    }

    /**
     * Queue one deduplicated drain task that delivers all due rows (used by sweep, reconcile and administrator
     * actions instead of one ad hoc task per row).
     */
    public static function queue_drain(): void {
        $task = new \local_lticompletionsync\task\dispatch_task();
        $task->set_custom_data(['drain' => 1]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Make the retry rows of a connection due now (administrator repair after an outage or a key change).
     *
     * @param int $connectionid
     * @return int number of rows
     */
    public static function retry_now(int $connectionid): int {
        global $DB;
        $params = ['cid' => $connectionid, 'retry' => self::STATUS_RETRY];
        $count = $DB->count_records_select(self::TABLE, 'connectionid = :cid AND status = :retry', $params);
        if ($count) {
            $DB->execute(
                'UPDATE {' . self::TABLE . '} SET nextattempt = 0 WHERE connectionid = :cid AND status = :retry',
                $params
            );
            self::queue_drain();
        }
        return $count;
    }

    /**
     * Queue an ad hoc delivery for a row (deduplicated).
     *
     * @param int $rowid
     */
    public static function queue_delivery(int $rowid): void {
        $task = new \local_lticompletionsync\task\dispatch_task();
        $task->set_custom_data(['outid' => $rowid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Rows that are due for (re)delivery.
     *
     * @param int $limit
     * @param int|null $now
     * @return \stdClass[]
     */
    public static function due(int $limit = 200, ?int $now = null): array {
        global $DB;
        return $DB->get_records_select(
            self::TABLE,
            'status IN (:pending, :retry) AND nextattempt <= :now',
            ['pending' => self::STATUS_PENDING, 'retry' => self::STATUS_RETRY, 'now' => $now ?? time()],
            'nextattempt ASC, id ASC',
            '*',
            0,
            $limit
        );
    }

    /**
     * Put a dead or blocked row back into the queue (administrator action). The version is kept: the receiver
     * answers a repeated version idempotently. The subject is read again at delivery, so a learner who launched
     * again after a pseudonym rotation is reached with the new pseudonym (repair path of a fail-closed rotation).
     *
     * @param int $rowid
     * @param bool $queue queue an ad hoc delivery for the row
     */
    public static function requeue(int $rowid, bool $queue = true): void {
        global $DB;
        $DB->update_record(self::TABLE, (object) [
            'id' => $rowid,
            'status' => self::STATUS_PENDING,
            'attempts' => 0,
            'nextattempt' => 0,
            'timemodified' => time(),
        ]);
        if ($queue) {
            self::queue_delivery($rowid);
        }
    }

    /**
     * Requeue all dead and blocked rows of a connection (administrator action, one drain task).
     *
     * @param int $connectionid
     * @return int number of rows
     */
    public static function requeue_connection(int $connectionid): int {
        global $DB;
        $rows = $DB->get_fieldset_select(self::TABLE, 'id', 'connectionid = :id AND status IN (:dead, :blocked)', [
            'id' => $connectionid, 'dead' => self::STATUS_DEAD, 'blocked' => self::STATUS_BLOCKED,
        ]);
        foreach ($rows as $rowid) {
            self::requeue((int) $rowid, false);
        }
        if ($rows) {
            self::queue_drain();
        }
        return count($rows);
    }

    /**
     * Write delivery fields of a row only if no newer version was recorded meanwhile (optimistic concurrency).
     *
     * @param \stdClass $row row as read before the delivery (its version is the expected one)
     * @param string[] $fields names of the fields to write
     * @return bool false when the row was superseded by a newer version
     */
    public static function save_if_current(\stdClass $row, array $fields): bool {
        global $DB;
        $sets = [];
        $params = ['id' => $row->id, 'expectedversion' => (int) $row->version];
        foreach ($fields as $name) {
            if ($row->$name === null) {
                $sets[] = "$name = NULL";
            } else {
                $sets[] = "$name = :f_$name";
                $params['f_' . $name] = $row->$name;
            }
        }
        $DB->execute('UPDATE {' . self::TABLE . '} SET ' . implode(', ', $sets) .
            ' WHERE id = :id AND version = :expectedversion', $params);
        return (int) $DB->get_field(self::TABLE, 'version', ['id' => $row->id]) === (int) $row->version;
    }

    /**
     * Schedule the next attempt with exponential backoff and jitter, or move to the dead letter state.
     *
     * @param \stdClass $row
     * @param string $error code
     * @return string new status
     */
    public static function schedule_retry(\stdClass $row, string $error): string {
        $attempts = (int) $row->attempts + 1;
        $row->attempts = $attempts;
        $row->lasterror = \core_text::substr($error, 0, 64);
        $row->timemodified = time();
        $courseid = self::course_of($row);
        $cmid = $row->sourcekind === source::KIND_CM ? (int) $row->sourceid : 0;
        $fields = ['attempts', 'lasterror', 'timemodified', 'status', 'nextattempt'];
        if ($attempts >= config::max_attempts()) {
            $row->status = self::STATUS_DEAD;
            $row->nextattempt = 0;
            if (!self::save_if_current($row, $fields)) {
                return self::STATUS_PENDING;
            }
            journal::record(
                'dead_letter',
                $row->correlationid,
                (int) $row->connectionid,
                (int) $row->userid,
                $courseid,
                $cmid,
                (int) $row->version,
                'attempts:' . $error
            );
            return $row->status;
        }
        $delay = config::backoff_delay($attempts);
        $delay += random_int(0, max(1, intdiv($delay, 10)));
        $row->status = self::STATUS_RETRY;
        $row->nextattempt = time() + $delay;
        if (!self::save_if_current($row, $fields)) {
            return self::STATUS_PENDING;
        }
        journal::record(
            'retry_scheduled',
            $row->correlationid,
            (int) $row->connectionid,
            (int) $row->userid,
            $courseid,
            $cmid,
            (int) $row->version,
            $error . ':+' . $delay . 's'
        );
        return $row->status;
    }
}
