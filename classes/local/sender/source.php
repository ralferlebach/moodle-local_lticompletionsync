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

/**
 * Tool side: maps a local completion change to the LTI 1.3 resource links (published enrol_lti resources) it
 * belongs to, and computes the provider state.
 *
 * A published activity reports its activity completion; a published course reports its course completion (read from
 * the stored course completion, never guessed from single activities).
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class source {
    /** @var string Source kind: activity completion. */
    public const KIND_CM = 'cm';
    /** @var string Source kind: course completion. */
    public const KIND_COURSE = 'course';

    /** @var string Base SQL of the user/resource link relation (LTI 1.3 resources only). */
    private const BASE_SQL = "SELECT url.id AS urlid, rl.id AS resourcelinkid, rl.ltideploymentid, lu.toolid, lu.userid,
                                     t.contextid
                                FROM {enrol_lti_users} lu
                                JOIN {enrol_lti_tools} t ON t.id = lu.toolid
                                JOIN {enrol_lti_user_resource_link} url ON url.ltiuserid = lu.id
                                JOIN {enrol_lti_resource_link} rl ON rl.id = url.resourcelinkid AND rl.resourceid = t.id
                               WHERE t.ltiversion = :ltiversion";

    /**
     * Resource link targets of a user for a published context, with their enabled sender connection.
     *
     * @param int $userid
     * @param \context $context module or course context of the change
     * @return \stdClass[] target objects: connection, resourcelinkid, toolid, userid, sourcekind, sourceid
     */
    public static function targets_for(int $userid, \context $context): array {
        global $DB;
        $rows = $DB->get_records_sql(
            self::BASE_SQL . ' AND lu.userid = :userid AND t.contextid = :contextid',
            ['ltiversion' => 'LTI-1p3', 'userid' => $userid, 'contextid' => $context->id]
        );
        return self::with_connections($rows);
    }

    /**
     * All resource link targets of an enabled sender connection (full reconcile).
     *
     * @param \stdClass $con
     * @return \stdClass[]
     */
    public static function targets_of_connection(\stdClass $con): array {
        global $DB;
        $rows = $DB->get_records_sql(
            self::BASE_SQL . ' AND rl.ltideploymentid = :dep',
            ['ltiversion' => 'LTI-1p3', 'dep' => $con->deploymentid]
        );
        return self::with_connections($rows);
    }

    /**
     * Attach the enabled sender connection and the source to raw relation rows.
     *
     * @param \stdClass[] $rows
     * @return \stdClass[]
     */
    private static function with_connections(array $rows): array {
        $targets = [];
        foreach ($rows as $row) {
            $con = connection::sender_for_deployment((int) $row->ltideploymentid);
            if (!$con) {
                continue;
            }
            $context = \context::instance_by_id((int) $row->contextid, IGNORE_MISSING);
            if (!$context || !in_array($context->contextlevel, [CONTEXT_MODULE, CONTEXT_COURSE])) {
                continue;
            }
            $key = $con->id . '/' . $row->resourcelinkid . '/' . $row->userid;
            $targets[$key] = (object) [
                'connection' => $con,
                'resourcelinkid' => (int) $row->resourcelinkid,
                'toolid' => (int) $row->toolid,
                'userid' => (int) $row->userid,
                'sourcekind' => $context->contextlevel == CONTEXT_MODULE ? self::KIND_CM : self::KIND_COURSE,
                'sourceid' => (int) $context->instanceid,
            ];
        }
        return array_values($targets);
    }

    /**
     * Provider state of a target: 1 completed, 0 not completed, null when the source has no completion at all.
     *
     * @param string $kind
     * @param int $sourceid cmid or course id
     * @param int $userid
     * @return int|null
     */
    public static function provider_state(string $kind, int $sourceid, int $userid): ?int {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');
        if ($kind === self::KIND_CM) {
            $courseid = $DB->get_field('course_modules', 'course', ['id' => $sourceid]);
            if (!$courseid) {
                return null;
            }
            $cm = get_fast_modinfo($courseid)->get_cm($sourceid);
            $completion = new \completion_info($cm->get_course());
            if (!$completion->is_enabled($cm)) {
                return null;
            }
            $data = $completion->get_data($cm, false, $userid);
            return in_array((int) $data->completionstate, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true) ? 1 : 0;
        }
        $course = $DB->get_record('course', ['id' => $sourceid]);
        if (!$course) {
            return null;
        }
        $completion = new \completion_info($course);
        if (!$completion->is_enabled() || !$completion->has_criteria()) {
            return null;
        }
        $timecompleted = $DB->get_field('course_completions', 'timecompleted', ['userid' => $userid, 'course' => $sourceid]);
        return empty($timecompleted) ? 0 : 1;
    }

    /**
     * The LTI subject of a user for a published resource, as the platform sent it at launch (pseudonym or id).
     *
     * @param int $userid
     * @param int $toolid
     * @return string|null
     */
    public static function subject(int $userid, int $toolid): ?string {
        global $DB;
        $sub = $DB->get_field('enrol_lti_users', 'sourceid', ['userid' => $userid, 'toolid' => $toolid]);
        return ($sub === false || $sub === null || $sub === '') ? null : (string) $sub;
    }
}
