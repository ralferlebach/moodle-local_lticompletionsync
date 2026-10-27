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

namespace local_lticompletionsync\local;

/**
 * Transfer journal: the standardised events of spec section 7, recorded in the plugin's own table (for the reduced
 * status view that works without tool_ltidiagnostics) and triggered as Moodle events (for log stores and for
 * tool_ltidiagnostics, which reads the standard log).
 *
 * Only codes, ids and the correlation id are recorded: never a JWT, a token, a key or a payload.
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class journal {
    /** @var string Table. */
    public const TABLE = 'local_lticompletionsync_log';

    /** @var string[] Moodle event class of each standard name (event class names need an object_action form). */
    public const CLASSES = [
        'source_detected' => 'source_detected', 'queued' => 'message_queued', 'sent' => 'message_sent',
        'http_received' => 'http_received', 'identity_resolved' => 'identity_resolved',
        'completion_applied' => 'completion_applied', 'completion_revoked' => 'completion_revoked',
        'acknowledged' => 'message_acknowledged', 'retry_scheduled' => 'retry_scheduled', 'dead_letter' => 'dead_letter',
        'reconciled' => 'state_reconciled',
    ];

    /** @var string[] Standard event names (spec section 7). */
    public const EVENTS = [
        'source_detected', 'queued', 'sent', 'http_received', 'identity_resolved', 'completion_applied',
        'completion_revoked', 'acknowledged', 'retry_scheduled', 'dead_letter', 'reconciled',
    ];

    /**
     * Record one event.
     *
     * @param string $event one of EVENTS
     * @param string $correlationid
     * @param int $connectionid
     * @param int $userid local user concerned (0 when none)
     * @param int $courseid
     * @param int $cmid
     * @param int $version
     * @param string $detail short code, will be cut to 255 characters
     */
    public static function record(
        string $event,
        string $correlationid,
        int $connectionid,
        int $userid = 0,
        int $courseid = 0,
        int $cmid = 0,
        int $version = 0,
        string $detail = ''
    ): void {
        global $DB;
        if (!in_array($event, self::EVENTS, true)) {
            throw new \coding_exception('Unknown journal event ' . $event);
        }
        if (!protocol::valid_correlation_id($correlationid)) {
            $correlationid = '00000000-0000-0000-0000-000000000000';
        }
        $detail = \core_text::substr(clean_param($detail, PARAM_NOTAGS), 0, 255);
        $DB->insert_record(self::TABLE, (object) [
            'correlationid' => $correlationid,
            'connectionid' => $connectionid,
            'eventname' => $event,
            'userid' => $userid,
            'courseid' => $courseid,
            'cmid' => $cmid,
            'version' => $version,
            'detail' => $detail === '' ? null : $detail,
            'timecreated' => time(),
        ]);

        $context = \context_system::instance();
        if ($cmid > 0) {
            $context = \context_module::instance($cmid, IGNORE_MISSING) ?: $context;
        } else if ($courseid > 0) {
            $context = \context_course::instance($courseid, IGNORE_MISSING) ?: $context;
        }
        $class = '\\local_lticompletionsync\\event\\' . self::CLASSES[$event];
        $params = [
            'context' => $context,
            'other' => [
                'correlationid' => $correlationid,
                'connectionid' => $connectionid,
                'version' => $version,
                'detail' => $detail,
            ],
        ];
        if ($userid > 0) {
            $params['relateduserid'] = $userid;
        }
        $class::create($params)->trigger();
    }

    /**
     * Recent journal rows, optionally filtered.
     *
     * @param array $filter keys correlationid, courseid, connectionid
     * @param int $limit
     * @return \stdClass[]
     */
    public static function recent(array $filter, int $limit = 100): array {
        global $DB;
        $where = [];
        $params = [];
        foreach (['correlationid', 'courseid', 'connectionid'] as $key) {
            if (!empty($filter[$key])) {
                $where[] = "$key = :$key";
                $params[$key] = $filter[$key];
            }
        }
        return $DB->get_records_select(
            self::TABLE,
            $where ? implode(' AND ', $where) : '',
            $params,
            'timecreated DESC, id DESC',
            '*',
            0,
            $limit
        );
    }

    /**
     * Delete journal rows older than the retention period.
     *
     * @param int|null $now
     * @return int number of deleted rows (approximate)
     */
    public static function purge_old(?int $now = null): int {
        global $DB;
        $before = ($now ?? time()) - config::journal_retention_days() * DAYSECS;
        $count = $DB->count_records_select(self::TABLE, 'timecreated < :before', ['before' => $before]);
        $DB->delete_records_select(self::TABLE, 'timecreated < :before', ['before' => $before]);
        return $count;
    }
}
