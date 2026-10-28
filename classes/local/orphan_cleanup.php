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

use local_lticompletionsync\local\receiver\activity_manager;
use local_lticompletionsync\local\receiver\endpoint;
use local_lticompletionsync\local\receiver\processor;
use local_lticompletionsync\local\sender\outbox;

/**
 * Removal of plugin rows whose subject no longer exists (data retention, privacy).
 *
 * Core's remove_course_contents() deletes the modules of a deleted (or emptied) course without triggering
 * course_module_deleted, mod_lti's lti_delete_type() triggers no event at all, and enrol_lti registrations and
 * deployments are deleted without events. Therefore: targeted cleanup on \core\event\course_content_deleted (every
 * course deletion and every "delete contents" restore) and \core\event\enrol_instance_deleted (published tool removed),
 * plus a bounded sweep in the daily cleanup task as the safety net (and in the upgrade to 2026102701 for existing
 * orphans). Rows are deleted, never exported or kept; native completion and grades are core data and not touched.
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class orphan_cleanup {
    /** @var int Default maximum rows deleted per check and run. */
    public const LIMIT = 5000;

    /**
     * A course's contents were deleted: its sender rows (course source), journal rows and every row of activities that
     * no longer exist.
     *
     * @param int $courseid
     * @return array counts per check
     */
    public static function course_deleted(int $courseid): array {
        global $DB;
        $counts = [
            'out_course' => self::delete_ids(outbox::TABLE, $DB->get_fieldset_select(
                outbox::TABLE,
                'id',
                'sourcekind = :k AND sourceid = :c',
                ['k' => sender\source::KIND_COURSE, 'c' => $courseid]
            )),
            'log_course' => self::delete_ids(journal::TABLE, $DB->get_fieldset_select(
                journal::TABLE,
                'id',
                'courseid = :c',
                ['c' => $courseid]
            )),
        ];
        return $counts + self::sweep_activities(0);
    }

    /**
     * Rows of activities that no longer exist (receiver bookkeeping, receiver states, sender rows of activity sources).
     *
     * @param int $limit 0 = no limit
     * @return array counts
     */
    private static function sweep_activities(int $limit): array {
        return [
            'act' => self::delete_missing(activity_manager::TABLE, 'cmid', 'course_modules', $limit),
            'rcv_cm' => self::delete_missing(processor::TABLE, 'cmid', 'course_modules', $limit),
            'out_cm' => self::delete_missing(
                outbox::TABLE,
                'sourceid',
                'course_modules',
                $limit,
                "t.sourcekind = '" . sender\source::KIND_CM . "'"
            ),
        ];
    }

    /**
     * The safety-net sweep: every check, bounded by $limit rows each.
     *
     * @param int $limit 0 = no limit (upgrade step)
     * @return array counts per check
     */
    public static function sweep(int $limit = self::LIMIT): array {
        global $DB;
        $counts = ['connections' => 0];
        // Connections whose LTI tool type (receiver) or enrol_lti deployment/registration (sender) was deleted.
        $sql = "SELECT c.id FROM {" . connection::TABLE . "} c
             LEFT JOIN {lti_types} t ON t.id = c.ltitypeid
                 WHERE c.role = :r AND t.id IS NULL
                 UNION
                SELECT c.id FROM {" . connection::TABLE . "} c
             LEFT JOIN {enrol_lti_deployment} d ON d.id = c.deploymentid
             LEFT JOIN {enrol_lti_app_registration} g ON g.id = c.registrationid
                 WHERE c.role = :s AND (d.id IS NULL OR g.id IS NULL)";
        foreach ($DB->get_fieldset_sql($sql, ['r' => connection::ROLE_RECEIVER, 's' => connection::ROLE_SENDER]) as $id) {
            connection::delete((int) $id);
            $counts['connections']++;
        }
        $counts += self::sweep_activities($limit);
        $counts['out_course'] = self::delete_missing(
            outbox::TABLE,
            'sourceid',
            'course',
            $limit,
            "t.sourcekind = '" . sender\source::KIND_COURSE . "'"
        );
        $counts['out_tool'] = self::delete_missing(outbox::TABLE, 'toolid', 'enrol_lti_tools', $limit);
        $counts['out_user'] = self::delete_missing(outbox::TABLE, 'userid', 'user', $limit, '', 'deleted = 0');
        $counts['rcv_user'] = self::delete_missing(processor::TABLE, 'userid', 'user', $limit, '', 'deleted = 0');
        $counts['out_con'] = self::delete_missing(outbox::TABLE, 'connectionid', connection::TABLE, $limit);
        $counts['rcv_con'] = self::delete_missing(processor::TABLE, 'connectionid', connection::TABLE, $limit);
        $counts['jti_con'] = self::delete_missing(endpoint::JTI_TABLE, 'connectionid', connection::TABLE, $limit);
        $counts['log_course'] = self::delete_missing(journal::TABLE, 'courseid', 'course', $limit, 't.courseid > 0');
        $counts['log_user'] = self::delete_missing(journal::TABLE, 'userid', 'user', $limit, 't.userid > 0', 'deleted = 0');
        return $counts;
    }

    /**
     * Delete rows of $table whose $field points to no row of $ref (with $refwhere on the referenced row).
     *
     * @param string $table
     * @param string $field
     * @param string $ref referenced table (id column)
     * @param int $limit 0 = no limit
     * @param string $where extra condition on the plugin table (alias t)
     * @param string $refwhere extra condition on the referenced row (alias r), e.g. "deleted = 0"
     * @return int deleted rows
     */
    private static function delete_missing(
        string $table,
        string $field,
        string $ref,
        int $limit,
        string $where = '',
        string $refwhere = ''
    ): int {
        global $DB;
        $join = $refwhere === '' ? '' : ' AND r.' . $refwhere;
        $sql = "SELECT t.id FROM {" . $table . "} t LEFT JOIN {" . $ref . "} r ON r.id = t.$field$join
                 WHERE r.id IS NULL" . ($where === '' ? '' : " AND $where") . " ORDER BY t.id";
        return self::delete_ids($table, array_keys($DB->get_records_sql($sql, [], 0, $limit)));
    }

    /**
     * Delete rows by id in chunks.
     *
     * @param string $table
     * @param array $ids
     * @return int
     */
    private static function delete_ids(string $table, array $ids): int {
        global $DB;
        foreach (array_chunk($ids, 500) as $chunk) {
            $DB->delete_records_list($table, 'id', $chunk);
        }
        return count($ids);
    }
}
