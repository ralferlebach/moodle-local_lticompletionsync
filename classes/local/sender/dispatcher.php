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
use local_lticompletionsync\local\protocol;

/**
 * Tool side delivery of the outbox: one row at a time, under a lock, with acknowledgement handling.
 *
 * Only a verified "applied" answer for the current version counts as end-to-end confirmation (an HTTP 2xx alone does
 * not). Permanent refusals go to the dead letter state, transient failures are retried with exponential backoff.
 * Without a partner plugin the row is blocked with an explicit "no partner plugin" declaration, or, when the
 * connection enables it, reported through the optional tool-only AGS progress level (no end-to-end guarantee).
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dispatcher {
    /** @var channel */
    private $channel;
    /** @var ags_progress */
    private $ags;
    /** @var array connection id => row, cached for one run (deliver_due) */
    private $connections = [];
    /** @var array connection id => true when its partner was checked in this run */
    private $probed = [];

    /**
     * Constructor.
     *
     * @param channel|null $channel
     * @param ags_progress|null $ags
     */
    public function __construct(?channel $channel = null, ?ags_progress $ags = null) {
        $this->channel = $channel ?? new channel();
        $this->ags = $ags ?? new ags_progress();
    }

    /**
     * Deliver due rows, bounded by a number of rows and a time budget (the rest is picked up by the next sweep or
     * drain task; nothing re-queues itself endlessly).
     *
     * @param int $limit maximum number of rows (0 = setting "batchlimit")
     * @param int $budget maximum seconds (0 = setting "timebudget")
     * @return int number of rows processed
     */
    public function deliver_due(int $limit = 0, int $budget = 0): int {
        $limit = $limit > 0 ? $limit : \local_lticompletionsync\local\config::batch_limit();
        $budget = $budget > 0 ? $budget : \local_lticompletionsync\local\config::time_budget();
        $deadline = time() + $budget;
        $this->connections = [];
        $this->probed = [];
        $seen = [];
        $count = 0;
        while ($count < $limit && time() < $deadline) {
            $rows = outbox::due(min(source::BATCH, $limit - $count + count($seen)));
            $rows = array_filter($rows, fn($row) => !isset($seen[(int) $row->id]));
            if (!$rows) {
                break;
            }
            foreach ($rows as $row) {
                $seen[(int) $row->id] = true;
                $this->deliver((int) $row->id);
                $count++;
                if ($count >= $limit || time() >= $deadline) {
                    break;
                }
            }
        }
        $this->connections = [];
        return $count;
    }

    /**
     * Connection of a row (cached during deliver_due()).
     *
     * @param int $id
     * @return \stdClass|null
     */
    private function connection(int $id): ?\stdClass {
        if (!array_key_exists($id, $this->connections)) {
            $this->connections[$id] = connection::get($id);
        }
        return $this->connections[$id];
    }

    /**
     * Deliver one row (no-op when it is not due or locked by a parallel task).
     *
     * @param int $rowid
     * @return string resulting status ('' when nothing was done)
     */
    public function deliver(int $rowid): string {
        global $DB;
        $factory = \core\lock\lock_config::get_lock_factory('local_lticompletionsync');
        $lock = $factory->get_lock('out_' . $rowid, 0);
        if (!$lock) {
            return '';
        }
        try {
            $row = $DB->get_record(outbox::TABLE, ['id' => $rowid]);
            if (!$row || !in_array($row->status, [outbox::STATUS_PENDING, outbox::STATUS_RETRY], true)) {
                return '';
            }
            return $this->deliver_row($row);
        } finally {
            $lock->release();
        }
    }

    /**
     * Deliver a locked row.
     *
     * @param \stdClass $row
     * @return string resulting status
     */
    private function deliver_row(\stdClass $row): string {
        global $DB;
        $con = $this->connection((int) $row->connectionid);
        if (!$con || !$con->enabled || $con->role !== connection::ROLE_SENDER) {
            return $this->finish($row, outbox::STATUS_BLOCKED, 'connection_disabled', 'dead_letter');
        }
        $recheck = [connection::PARTNER_UNKNOWN, connection::PARTNER_ERROR, connection::PARTNER_DISABLED];
        // A platform without the plugin is checked again once a day (it may have installed it meanwhile).
        $stale = $con->partnerstatus === connection::PARTNER_NOPLUGIN && (int) $con->timechecked < time() - DAYSECS;
        $cid = (int) $con->id;
        if ($stale || in_array($con->partnerstatus, $recheck, true)) {
            if (isset($this->probed[$cid])) {
                // One capability check per connection and run: during an outage the remaining rows wait for their
                // retry instead of multiplying failing requests (bounded load, CS-04).
                return outbox::schedule_retry($row, 'partner_' . $con->partnerstatus);
            }
            $this->probed[$cid] = true;
            $this->channel->probe($con);
        }
        if ($con->partnerstatus === connection::PARTNER_NOPLUGIN) {
            return $this->without_partner($con, $row);
        }

        $rl = $DB->get_record('enrol_lti_resource_link', ['id' => $row->resourcelinkid]);
        $sub = source::subject((int) $row->userid, (int) $row->toolid);
        if (!$rl || $sub === null) {
            return $this->finish($row, outbox::STATUS_DEAD, 'no_subject', 'dead_letter');
        }
        // A newer state may have been recorded meanwhile: always send the current row content.
        $message = [
            'type' => protocol::TYPE_COMPLETION,
            'resource_link_id' => (string) $rl->resourcelinkid,
            'user' => $sub,
            'state' => protocol::state_name((int) $row->desiredstate),
            'activity_progress' => $row->desiredstate ? 'Completed' : 'Initialized',
            'version' => (int) $row->version,
            'timestamp' => (int) $row->statetime,
            'correlation_id' => $row->correlationid,
            'source' => $row->sourcekind,
        ];
        $row->sentversion = $row->version;
        outbox::save_if_current($row, ['sentversion']);
        $courseid = outbox::course_of($row);
        $cmid = $row->sourcekind === source::KIND_CM ? (int) $row->sourceid : 0;
        journal::record(
            'sent',
            $row->correlationid,
            (int) $con->id,
            (int) $row->userid,
            $courseid,
            $cmid,
            (int) $row->version,
            'attempt:' . ((int) $row->attempts + 1)
        );

        $answer = $this->channel->exchange($con, $message);
        if ($answer['httpstatus'] === 404 && $answer['result'] === null) {
            $DB->set_field(connection::TABLE, 'partnerstatus', connection::PARTNER_NOPLUGIN, ['id' => $con->id]);
            $con->partnerstatus = connection::PARTNER_NOPLUGIN;
            return $this->without_partner($con, $row);
        }
        if ($answer['result'] === null) {
            $code = $answer['servererror'] !== '' ? $answer['error'] . ':' . $answer['servererror'] : $answer['error'];
            return outbox::schedule_retry($row, $code);
        }
        return $this->handle_result($row, $answer['result'], $courseid, $cmid);
    }

    /**
     * Interpret a verified answer.
     *
     * @param \stdClass $row
     * @param array $result answer claim
     * @param int $courseid
     * @param int $cmid
     * @return string resulting status
     */
    private function handle_result(\stdClass $row, array $result, int $courseid, int $cmid): string {
        $status = is_string($result['status'] ?? null) ? $result['status'] : '';
        $reason = is_string($result['reason'] ?? null) ? clean_param($result['reason'], PARAM_ALPHANUMEXT) : '';
        $applied = is_int($result['appliedversion'] ?? null) ? $result['appliedversion'] : 0;
        $row->ackstatus = in_array($status, [protocol::ACK_RECEIVED, protocol::ACK_VALIDATED, protocol::ACK_APPLIED,
            protocol::ACK_REJECTED, protocol::ACK_CONFLICT], true) ? $status : null;
        $row->ackversion = min($applied, (int) $row->version);

        if ($status === protocol::ACK_APPLIED && $applied >= (int) $row->version) {
            $row->lasterror = null;
            journal::record(
                'acknowledged',
                $row->correlationid,
                (int) $row->connectionid,
                (int) $row->userid,
                $courseid,
                $cmid,
                (int) $row->version,
                'applied'
            );
            return $this->finish($row, outbox::STATUS_ACKED, null, null);
        }
        if ($status === protocol::ACK_REJECTED && $reason === 'stale_version' && $applied >= (int) $row->version) {
            return $this->finish($row, outbox::STATUS_ACKED, null, 'acknowledged');
        }
        if ($status === protocol::ACK_REJECTED && $reason === 'superseded') {
            // The platform holds a newer state of the same local user from another subject (account merge there).
            return $this->finish($row, outbox::STATUS_ACKED, 'superseded', 'acknowledged');
        }
        if ($status === protocol::ACK_CONFLICT) {
            journal::record(
                'acknowledged',
                $row->correlationid,
                (int) $row->connectionid,
                (int) $row->userid,
                $courseid,
                $cmid,
                (int) $row->version,
                'conflict:' . $reason
            );
            return $this->finish($row, outbox::STATUS_BLOCKED, 'conflict_' . $reason, null);
        }
        if ($status === protocol::ACK_REJECTED && in_array($reason, protocol::PERMANENT_REASONS, true)) {
            return $this->finish($row, outbox::STATUS_DEAD, $reason, 'dead_letter');
        }
        $code = 'ack_' . ($status !== '' ? $status : 'unknown') . ($reason !== '' ? ':' . $reason : '');
        return outbox::schedule_retry($row, $code);
    }

    /**
     * No partner plugin at the platform: tool-only AGS progress (when enabled) or an explicit block.
     *
     * @param \stdClass $con
     * @param \stdClass $row
     * @return string resulting status
     */
    private function without_partner(\stdClass $con, \stdClass $row): string {
        if (!$con->agsprogress) {
            return $this->finish($row, outbox::STATUS_BLOCKED, 'no_partner_plugin', 'dead_letter');
        }
        $agsstatus = $this->ags->send($row);
        $row->agsstatus = $agsstatus;
        if ($agsstatus === ags_progress::RESULT_SENT) {
            // Delivered on the standard level only: no confirmation that native completion was set.
            return $this->finish($row, outbox::STATUS_SENT, 'tool_only_no_confirmation', 'acknowledged');
        }
        if ($agsstatus === ags_progress::RESULT_UNSUPPORTED) {
            return $this->finish($row, outbox::STATUS_BLOCKED, 'ags_not_permitted', 'dead_letter');
        }
        return outbox::schedule_retry($row, 'ags_error');
    }

    /**
     * Store a final status.
     *
     * @param \stdClass $row
     * @param string $status
     * @param string|null $error
     * @param string|null $event journal event to record
     * @return string
     */
    private function finish(\stdClass $row, string $status, ?string $error, ?string $event): string {
        $row->status = $status;
        $row->lasterror = $error;
        $row->nextattempt = 0;
        $row->timemodified = time();
        $fields = ['status', 'lasterror', 'nextattempt', 'timemodified', 'ackstatus', 'ackversion', 'agsstatus'];
        foreach (['ackstatus', 'ackversion', 'agsstatus'] as $name) {
            $row->$name = $row->$name ?? null;
        }
        if ($row->ackversion === null) {
            $row->ackversion = 0;
        }
        if (!outbox::save_if_current($row, $fields)) {
            // A newer version was recorded during the delivery; it is queued on its own.
            return outbox::STATUS_PENDING;
        }
        if ($event !== null) {
            $courseid = outbox::course_of($row);
            $cmid = $row->sourcekind === source::KIND_CM ? (int) $row->sourceid : 0;
            journal::record(
                $event,
                $row->correlationid,
                (int) $row->connectionid,
                (int) $row->userid,
                $courseid,
                $cmid,
                (int) $row->version,
                (string) $error
            );
        }
        return $status;
    }
}
