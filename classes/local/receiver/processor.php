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
use local_lticompletionsync\local\journal;
use local_lticompletionsync\local\protocol;

/**
 * Receiver state machine: received -> validated -> applied | rejected | conflict, versioned per activity and user.
 *
 * Versions are strictly monotonic per (connection, activity, user): an older version never changes anything
 * (out-of-order), the same version is answered from the stored state without a second application (duplicate,
 * lost response), a newer version is applied through the native completion adapter. No grade is read or written.
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class processor {
    /** @var string Table. */
    public const TABLE = 'local_lticompletionsync_rcv';

    /** @var bool True while this plugin itself changes a completion state (observer ignores own changes). */
    public static $applying = false;

    /** @var identity_resolver */
    private $identities;

    /**
     * Constructor.
     *
     * @param identity_resolver|null $identities
     */
    public function __construct(?identity_resolver $identities = null) {
        $this->identities = $identities ?? new identity_resolver();
    }

    /**
     * Validate the shape of a completion message.
     *
     * @param array $msg
     * @return array normalised message
     * @throws rejection bad_message
     */
    public static function normalise(array $msg): array {
        $rlid = $msg['resource_link_id'] ?? null;
        $sub = $msg['user'] ?? null;
        $state = is_string($msg['state'] ?? null) ? protocol::state_value($msg['state']) : null;
        $version = $msg['version'] ?? null;
        $timestamp = $msg['timestamp'] ?? 0;
        $corr = $msg['correlation_id'] ?? '';
        if (
            !is_string($rlid) || !ctype_digit($rlid) || strlen($rlid) > 10
            || !is_string($sub) || $sub === '' || strlen($sub) > 255
            || $state === null
            || !is_int($version) || $version <= 0
            || !is_int($timestamp) || $timestamp < 0
            || !is_string($corr) || !protocol::valid_correlation_id($corr)
        ) {
            throw new rejection('bad_message');
        }
        return [
            'resource_link_id' => (int) $rlid,
            'user' => $sub,
            'state' => $state,
            'version' => $version,
            'timestamp' => $timestamp,
            'correlation_id' => $corr,
        ];
    }

    /**
     * Resolve the activity and the user of a message and check that the activity can be managed.
     *
     * @param \stdClass $con receiver connection
     * @param array $msg normalised message
     * @return array [cm_info, userid]
     * @throws rejection
     */
    public function resolve(\stdClass $con, array $msg): array {
        global $DB, $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        $lti = $DB->get_record('lti', ['id' => $msg['resource_link_id']], 'id, course, typeid');
        if (!$lti) {
            throw new rejection('unknown_resource_link');
        }
        if ((int) $lti->typeid !== (int) $con->ltitypeid) {
            throw new rejection('wrong_tool');
        }
        $cmrecord = get_coursemodule_from_instance('lti', $lti->id, $lti->course, false, IGNORE_MISSING);
        if (!$cmrecord) {
            throw new rejection('unknown_resource_link');
        }
        $cm = get_fast_modinfo($lti->course)->get_cm($cmrecord->id);
        $reason = completion_adapter::unsupported_reason($cm);
        if ($reason !== '') {
            throw new rejection('unsupported_' . $reason);
        }
        activity_manager::require_managed($cm, $con);
        $type = $DB->get_record('lti_types', ['id' => $con->ltitypeid], '*', MUST_EXIST);
        $userid = $this->identities->resolve($type, (int) $lti->course, (int) $cm->id, $msg['user']);
        journal::record(
            'identity_resolved',
            $msg['correlation_id'],
            (int) $con->id,
            $userid,
            (int) $lti->course,
            (int) $cm->id,
            $msg['version'],
            $this->identities->method
        );
        $completion = new \completion_info($cm->get_course());
        if (!$completion->is_tracked_user($userid)) {
            throw new rejection('unsupported_' . completion_adapter::REASON_USER_NOT_TRACKED);
        }
        return [$cm, $userid];
    }

    /**
     * The state row of a target, created when missing (race-safe through the unique index).
     *
     * @param int $connectionid
     * @param int $cmid
     * @param int $userid
     * @param string $correlationid
     * @return \stdClass
     */
    public static function state_row(int $connectionid, int $cmid, int $userid, string $correlationid): \stdClass {
        global $DB;
        $key = ['connectionid' => $connectionid, 'cmid' => $cmid, 'userid' => $userid];
        $row = $DB->get_record(self::TABLE, $key);
        if ($row) {
            return $row;
        }
        $now = time();
        try {
            $DB->insert_record(self::TABLE, (object) ($key + [
                'status' => protocol::ACK_RECEIVED,
                'correlationid' => $correlationid,
                'timecreated' => $now,
                'timemodified' => $now,
            ]));
        } catch (\dml_write_exception $e) {
            // A parallel request created it.
            unset($e);
        }
        return $DB->get_record(self::TABLE, $key, '*', MUST_EXIST);
    }

    /**
     * Process a completion message.
     *
     * @param \stdClass $con receiver connection (enabled, signature verified)
     * @param array $raw message claim
     * @return array result: status, reason, version, appliedversion, state, correlation_id
     */
    public function completion(\stdClass $con, array $raw): array {
        try {
            $msg = self::normalise($raw);
        } catch (rejection $e) {
            return ['status' => protocol::ACK_REJECTED, 'reason' => $e->reason, 'version' => 0, 'appliedversion' => 0];
        }
        $result = [
            'version' => $msg['version'],
            'correlation_id' => $msg['correlation_id'],
            'appliedversion' => 0,
        ];
        try {
            [$cm, $userid] = $this->resolve($con, $msg);
        } catch (rejection $e) {
            $detail = 'rejected:' . $e->reason;
            journal::record('reconciled', $msg['correlation_id'], (int) $con->id, 0, 0, 0, $msg['version'], $detail);
            return $result + ['status' => $e->status, 'reason' => $e->reason];
        }

        // Serialise parallel requests for the same target, so that a version check and its application cannot
        // interleave with another version (a late retry of an older version running in parallel).
        $factory = \core\lock\lock_config::get_lock_factory('local_lticompletionsync');
        $lock = $factory->get_lock('rcv_' . (int) $con->id . '_' . (int) $cm->id . '_' . $userid, 10);
        if (!$lock) {
            return $result + ['status' => protocol::ACK_RECEIVED, 'reason' => 'busy'];
        }
        try {
            return $this->completion_locked($con, $msg, $cm, $userid, $result);
        } finally {
            $lock->release();
        }
    }

    /**
     * Version check and application of a resolved completion message (under the target lock).
     *
     * @param \stdClass $con
     * @param array $msg normalised message
     * @param \cm_info $cm
     * @param int $userid
     * @param array $result partial result
     * @return array result
     */
    private function completion_locked(\stdClass $con, array $msg, \cm_info $cm, int $userid, array $result): array {
        global $DB;
        $row = self::state_row((int) $con->id, (int) $cm->id, $userid, $msg['correlation_id']);
        $result['appliedversion'] = (int) $row->appliedversion;
        if ($msg['version'] < (int) $row->remoteversion) {
            // Out of order: an older provider state never overrides a newer one.
            journal::record(
                'reconciled',
                $msg['correlation_id'],
                (int) $con->id,
                $userid,
                (int) $cm->course,
                (int) $cm->id,
                $msg['version'],
                'stale:' . $row->remoteversion
            );
            return $result + ['status' => protocol::ACK_REJECTED, 'reason' => 'stale_version'];
        }
        if ($msg['version'] === (int) $row->remoteversion && $row->status !== protocol::ACK_RECEIVED) {
            // Duplicate (retry after a lost response): answer from the stored state, apply nothing twice.
            journal::record(
                'reconciled',
                $msg['correlation_id'],
                (int) $con->id,
                $userid,
                (int) $cm->course,
                (int) $cm->id,
                $msg['version'],
                'duplicate'
            );
            return $result + ['status' => $row->status, 'reason' => (string) $row->reason];
        }

        $row->remoteversion = $msg['version'];
        $row->remotestate = $msg['state'];
        $row->remotetime = $msg['timestamp'];
        $row->correlationid = $msg['correlation_id'];
        $row->status = protocol::ACK_VALIDATED;
        $row->reason = null;
        $row->timemodified = time();
        $DB->update_record(self::TABLE, $row);

        return array_merge($result, $this->apply_row($con, $cm, $userid, $row));
    }

    /**
     * Bring the native completion of one target to the stored provider state (also used to re-assert it).
     *
     * @param \stdClass $con
     * @param \cm_info $cm
     * @param int $userid
     * @param \stdClass $row state row (remoteversion/remotestate set)
     * @return array status, reason, appliedversion
     */
    public function apply_row(\stdClass $con, \cm_info $cm, int $userid, \stdClass $row): array {
        global $DB;
        $wanted = (int) $row->remotestate;
        $current = completion_adapter::current_state($cm, $userid);
        $override = completion_adapter::overridden_by($cm, $userid);
        if ($current !== $wanted && $override !== null && $con->conflictpolicy === connection::POLICY_LOCAL) {
            $row->status = protocol::ACK_CONFLICT;
            $row->reason = 'local_override';
            $row->timemodified = time();
            $DB->update_record(self::TABLE, $row);
            journal::record(
                'reconciled',
                $row->correlationid,
                (int) $con->id,
                $userid,
                (int) $cm->course,
                (int) $cm->id,
                (int) $row->remoteversion,
                'conflict:local_override'
            );
            return ['status' => $row->status, 'reason' => $row->reason, 'appliedversion' => (int) $row->appliedversion];
        }
        self::$applying = true;
        try {
            $outcome = completion_adapter::apply($cm, $userid, $wanted === 1);
        } catch (\moodle_exception $e) {
            $row->status = protocol::ACK_REJECTED;
            $row->reason = \core_text::substr($e->errorcode, 0, 64);
            $row->timemodified = time();
            $DB->update_record(self::TABLE, $row);
            return ['status' => $row->status, 'reason' => $row->reason, 'appliedversion' => (int) $row->appliedversion];
        } finally {
            self::$applying = false;
        }
        if (completion_adapter::current_state($cm, $userid) !== $wanted) {
            $row->status = protocol::ACK_REJECTED;
            $row->reason = 'not_applied';
        } else {
            $row->status = protocol::ACK_APPLIED;
            $row->reason = null;
            $row->appliedversion = $row->remoteversion;
        }
        $row->timemodified = time();
        $DB->update_record(self::TABLE, $row);
        if ($row->status === protocol::ACK_APPLIED) {
            $event = $outcome === completion_adapter::RESULT_UNCHANGED ? 'reconciled' :
                ($wanted ? 'completion_applied' : 'completion_revoked');
            journal::record(
                $event,
                $row->correlationid,
                (int) $con->id,
                $userid,
                (int) $cm->course,
                (int) $cm->id,
                (int) $row->remoteversion,
                $outcome
            );
        }
        return ['status' => $row->status, 'reason' => (string) $row->reason, 'appliedversion' => (int) $row->appliedversion];
    }

    /**
     * Answer a status query: the stored state of a target.
     *
     * @param \stdClass $con
     * @param array $raw message claim (resource_link_id, user, correlation_id)
     * @return array
     */
    public function status(\stdClass $con, array $raw): array {
        global $DB;
        $raw += ['state' => protocol::STATE_INCOMPLETE, 'version' => 1, 'timestamp' => 0];
        try {
            $msg = self::normalise($raw);
            [$cm, $userid] = $this->resolve($con, $msg);
        } catch (rejection $e) {
            return ['status' => $e->status, 'reason' => $e->reason, 'version' => 0, 'appliedversion' => 0];
        }
        $row = $DB->get_record(self::TABLE, ['connectionid' => $con->id, 'cmid' => $cm->id, 'userid' => $userid]);
        return [
            'status' => $row ? $row->status : 'none',
            'reason' => $row ? (string) $row->reason : '',
            'version' => $row ? (int) $row->remoteversion : 0,
            'appliedversion' => $row ? (int) $row->appliedversion : 0,
            'state' => protocol::state_name(completion_adapter::current_state($cm, $userid)),
        ];
    }

    /**
     * React to a completion change that this plugin did not make (teacher override, lockdown removed, reset).
     *
     * With the provider policy the stored provider state is re-asserted by an ad hoc task; with the local policy the
     * target is marked as conflict. Nothing happens for activities that are not managed.
     *
     * @param int $cmid
     * @param int $userid
     */
    public static function local_change(int $cmid, int $userid): void {
        global $DB;
        if (self::$applying) {
            return;
        }
        $rows = $DB->get_records(self::TABLE, ['cmid' => $cmid, 'userid' => $userid]);
        foreach ($rows as $row) {
            if ((int) $row->appliedversion === 0 && $row->status !== protocol::ACK_APPLIED) {
                continue;
            }
            $con = connection::get((int) $row->connectionid);
            if (!$con || !$con->enabled) {
                continue;
            }
            $cm = get_fast_modinfo($DB->get_field('course_modules', 'course', ['id' => $cmid]))->get_cm($cmid);
            if (completion_adapter::current_state($cm, $userid) === (int) $row->remotestate) {
                continue;
            }
            if ($con->conflictpolicy === connection::POLICY_LOCAL) {
                $row->status = protocol::ACK_CONFLICT;
                $row->reason = 'local_change';
                $row->timemodified = time();
                $DB->update_record(self::TABLE, $row);
                journal::record(
                    'reconciled',
                    $row->correlationid,
                    (int) $con->id,
                    $userid,
                    (int) $cm->course,
                    $cmid,
                    (int) $row->remoteversion,
                    'conflict:local_change'
                );
                continue;
            }
            $task = new \local_lticompletionsync\task\reassert_task();
            $task->set_custom_data(['rowid' => (int) $row->id]);
            \core\task\manager::queue_adhoc_task($task, true);
        }
    }
}
