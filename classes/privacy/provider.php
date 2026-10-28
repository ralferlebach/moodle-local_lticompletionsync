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

namespace local_lticompletionsync\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider.
 *
 * Personal data: the per-user transfer state on the tool side (outbox) and on the platform side (received state),
 * and the transfer journal. Sent to the partner site: the LTI subject (the identifier the platform itself issued,
 * possibly a pseudonym), the completion state and its version/time. No profile data is copied.
 *
 * Contexts: outbox rows belong to the published resource (module or course context), received rows to the LTI
 * activity, journal rows to the activity, the course or (when neither is known) the system context.
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** @var string[] Tables with a userid column. */
    private const TABLES = ['local_lticompletionsync_out', 'local_lticompletionsync_rcv', 'local_lticompletionsync_log'];

    /**
     * Describe the stored and transmitted data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_lticompletionsync_out', [
            'userid' => 'privacy:metadata:out:userid',
            'desiredstate' => 'privacy:metadata:out:desiredstate',
            'version' => 'privacy:metadata:out:version',
            'status' => 'privacy:metadata:out:status',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:out');
        $collection->add_database_table('local_lticompletionsync_rcv', [
            'userid' => 'privacy:metadata:rcv:userid',
            'remotestate' => 'privacy:metadata:rcv:remotestate',
            'remoteversion' => 'privacy:metadata:rcv:remoteversion',
            'status' => 'privacy:metadata:rcv:status',
            'subjecthash' => 'privacy:metadata:rcv:subjecthash',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:rcv');
        $collection->add_database_table('local_lticompletionsync_log', [
            'userid' => 'privacy:metadata:log:userid',
            'eventname' => 'privacy:metadata:log:eventname',
            'correlationid' => 'privacy:metadata:log:correlationid',
            'timecreated' => 'privacy:metadata:log:timecreated',
        ], 'privacy:metadata:log');
        $collection->add_external_location_link('ltiplatform', [
            'sub' => 'privacy:metadata:ltiplatform:sub',
            'state' => 'privacy:metadata:ltiplatform:state',
            'version' => 'privacy:metadata:ltiplatform:version',
        ], 'privacy:metadata:ltiplatform');
        return $collection;
    }

    /**
     * SQL selecting (contextid, userid) pairs of all stored rows.
     *
     * @return array [sql, params]
     */
    private static function pairs_sql(): array {
        $sql = "SELECT ctx.id AS contextid, o.userid
                  FROM {local_lticompletionsync_out} o
                  JOIN {context} ctx ON ctx.instanceid = o.sourceid AND ctx.contextlevel = :l1 AND o.sourcekind = 'cm'
                 UNION
                SELECT ctx.id AS contextid, o.userid
                  FROM {local_lticompletionsync_out} o
                  JOIN {context} ctx ON ctx.instanceid = o.sourceid AND ctx.contextlevel = :l2 AND o.sourcekind = 'course'
                 UNION
                SELECT ctx.id AS contextid, r.userid
                  FROM {local_lticompletionsync_rcv} r
                  JOIN {context} ctx ON ctx.instanceid = r.cmid AND ctx.contextlevel = :l3
                 UNION
                SELECT ctx.id AS contextid, g.userid
                  FROM {local_lticompletionsync_log} g
                  JOIN {context} ctx ON ctx.instanceid = g.cmid AND ctx.contextlevel = :l4
                 WHERE g.cmid > 0
                 UNION
                SELECT ctx.id AS contextid, g.userid
                  FROM {local_lticompletionsync_log} g
                  JOIN {context} ctx ON ctx.instanceid = g.courseid AND ctx.contextlevel = :l5
                 WHERE g.cmid = 0 AND g.courseid > 0
                 UNION
                SELECT ctx.id AS contextid, g.userid
                  FROM {local_lticompletionsync_log} g
                  JOIN {context} ctx ON ctx.contextlevel = :l6
                 WHERE g.cmid = 0 AND g.courseid = 0";
        return [$sql, [
            'l1' => CONTEXT_MODULE, 'l2' => CONTEXT_COURSE, 'l3' => CONTEXT_MODULE,
            'l4' => CONTEXT_MODULE, 'l5' => CONTEXT_COURSE, 'l6' => CONTEXT_SYSTEM,
        ]];
    }

    /**
     * Contexts with data of a user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        [$pairs, $params] = self::pairs_sql();
        $contextlist = new contextlist();
        $contextlist->add_from_sql(
            "SELECT p.contextid FROM ($pairs) p WHERE p.userid = :userid",
            $params + ['userid' => $userid]
        );
        return $contextlist;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        [$pairs, $params] = self::pairs_sql();
        $userlist->add_from_sql(
            'userid',
            "SELECT p.userid FROM ($pairs) p WHERE p.contextid = :contextid",
            $params + ['contextid' => $userlist->get_context()->id]
        );
    }

    /**
     * Row selection of each table for a context.
     *
     * @param \context $context
     * @return array table => [where, params]
     */
    private static function selectors(\context $context): array {
        if ($context->contextlevel == CONTEXT_MODULE) {
            return [
                'local_lticompletionsync_out' => ["sourcekind = 'cm' AND sourceid = :id", ['id' => $context->instanceid]],
                'local_lticompletionsync_rcv' => ['cmid = :id', ['id' => $context->instanceid]],
                'local_lticompletionsync_log' => ['cmid = :id', ['id' => $context->instanceid]],
            ];
        }
        if ($context->contextlevel == CONTEXT_COURSE) {
            return [
                'local_lticompletionsync_out' => ["sourcekind = 'course' AND sourceid = :id", ['id' => $context->instanceid]],
                'local_lticompletionsync_log' => ['cmid = 0 AND courseid = :id', ['id' => $context->instanceid]],
            ];
        }
        if ($context->contextlevel == CONTEXT_SYSTEM) {
            return ['local_lticompletionsync_log' => ['cmid = 0 AND courseid = 0', []]];
        }
        return [];
    }

    /**
     * Export the data of a user.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $data = [];
            foreach (self::selectors($context) as $table => [$where, $params]) {
                $rows = $DB->get_records_select($table, "$where AND userid = :userid", $params + ['userid' => $userid]);
                foreach ($rows as $row) {
                    $item = [];
                    foreach ((array) $row as $field => $value) {
                        if (in_array($field, ['id', 'userid', 'connectionid'], true)) {
                            continue;
                        }
                        $item[$field] = (strpos($field, 'time') === 0 && $value) ? transform::datetime($value) : $value;
                    }
                    $data[$table][] = $item;
                }
            }
            if ($data) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_lticompletionsync')],
                    (object) $data
                );
            }
        }
    }

    /**
     * Delete all data in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        foreach (self::selectors($context) as $table => [$where, $params]) {
            $DB->delete_records_select($table, $where, $params);
        }
    }

    /**
     * Delete the data of a user in some contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            foreach (self::selectors($context) as $table => [$where, $params]) {
                $DB->delete_records_select($table, "$where AND userid = :userid", $params + ['userid' => $userid]);
            }
        }
    }

    /**
     * Delete the data of some users in a context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        foreach (self::selectors($userlist->get_context()) as $table => [$where, $params]) {
            $DB->delete_records_select($table, "$where AND userid $insql", $params + $inparams);
        }
    }
}
