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
 * Tool side: maps completion changes to the LTI 1.3 resource links (published enrol_lti resources) they belong to, and
 * computes the provider state.
 *
 * A published activity reports its activity completion; a published course reports its course completion (read from
 * the stored course completion, never guessed from single activities).
 *
 * Bounded resources (CS-04): relation rows are read in keyset batches, provider states are read with one query per
 * source and batch, and sender connections and course/module facts are cached per process run instead of being looked
 * up per target. Nothing materialises all users of a course or connection.
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
    /** @var int Default batch size of the keyset iterations and IN() lists. */
    public const BATCH = 500;

    /** @var string Base SQL of the user/resource link relation (LTI 1.3 resources only). */
    private const BASE_SQL = "SELECT url.id AS urlid, rl.id AS resourcelinkid, rl.ltideploymentid, lu.toolid, lu.userid,
                                     t.contextid
                                FROM {enrol_lti_users} lu
                                JOIN {enrol_lti_tools} t ON t.id = lu.toolid
                                JOIN {enrol_lti_user_resource_link} url ON url.ltiuserid = lu.id
                                JOIN {enrol_lti_resource_link} rl ON rl.id = url.resourcelinkid AND rl.resourceid = t.id
                               WHERE t.ltiversion = :ltiversion";

    /** @var array deploymentid => connection row or false (per run). */
    private static $senders = [];
    /** @var array cmid => course id (per run). */
    private static $cmcourses = [];
    /** @var array "kind/sourceid" => bool completion available (per run). */
    private static $enabled = [];
    /** @var int Time the caches were started. */
    private static $cachetime = 0;
    /** @var int Maximum age of the caches in seconds (long-running cron processes, observers). */
    private const CACHE_TTL = 30;

    /**
     * Forget the per-run caches (tasks call this at their start; tests after changing connections).
     */
    public static function reset_caches(): void {
        self::$senders = [];
        self::$cmcourses = [];
        self::$enabled = [];
        self::$cachetime = time();
    }

    /**
     * Drop the caches when they are older than CACHE_TTL.
     */
    private static function fresh(): void {
        if (time() - self::$cachetime > self::CACHE_TTL) {
            self::reset_caches();
        }
    }

    /**
     * The enabled sender connection of a deployment (cached per run).
     *
     * @param int $deploymentid
     * @return \stdClass|null
     */
    public static function sender_for_deployment(int $deploymentid): ?\stdClass {
        self::fresh();
        if (!array_key_exists($deploymentid, self::$senders)) {
            self::$senders[$deploymentid] = connection::sender_for_deployment($deploymentid) ?? false;
        }
        return self::$senders[$deploymentid] ?: null;
    }

    /**
     * Resource link targets of a user for a published context, with their enabled sender connection.
     *
     * @param int $userid
     * @param \context $context module or course context of the change
     * @return \stdClass[] target objects: connection, resourcelinkid, toolid, userid, sourcekind, sourceid
     */
    public static function targets_for(int $userid, \context $context): array {
        return self::targets_for_users($context, [$userid]);
    }

    /**
     * Resource link targets of several users for one published context (one query).
     *
     * @param \context $context
     * @param int[] $userids at most a few hundred (callers batch)
     * @return \stdClass[]
     */
    public static function targets_for_users(\context $context, array $userids): array {
        global $DB;
        if (!$userids || !in_array($context->contextlevel, [CONTEXT_MODULE, CONTEXT_COURSE])) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(
            array_values(array_unique(array_map('intval', $userids))),
            SQL_PARAMS_NAMED,
            'u'
        );
        $rows = $DB->get_records_sql(
            self::BASE_SQL . " AND t.contextid = :contextid AND lu.userid $insql",
            ['ltiversion' => 'LTI-1p3', 'contextid' => $context->id] + $params
        );
        return self::with_connections($rows);
    }

    /**
     * Walk all resource link targets of an enabled sender connection in keyset batches (full reconcile).
     *
     * @param \stdClass $con
     * @param callable $fn called with each batch (\stdClass[] targets)
     * @param int $batch
     * @return int number of relation rows read
     */
    public static function each_target_batch(\stdClass $con, callable $fn, int $batch = self::BATCH): int {
        global $DB;
        $last = 0;
        $read = 0;
        while (true) {
            $rows = $DB->get_records_sql(
                self::BASE_SQL . ' AND rl.ltideploymentid = :dep AND url.id > :last ORDER BY url.id',
                ['ltiversion' => 'LTI-1p3', 'dep' => $con->deploymentid, 'last' => $last],
                0,
                $batch
            );
            if (!$rows) {
                break;
            }
            $read += count($rows);
            $last = (int) end($rows)->urlid;
            $fn(self::with_connections($rows));
            if (count($rows) < $batch) {
                break;
            }
        }
        return $read;
    }

    /**
     * All resource link targets of an enabled sender connection (small connections and tests only).
     *
     * @param \stdClass $con
     * @return \stdClass[]
     */
    public static function targets_of_connection(\stdClass $con): array {
        $all = [];
        self::each_target_batch($con, function (array $targets) use (&$all) {
            array_push($all, ...$targets);
        });
        return $all;
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
            $con = self::sender_for_deployment((int) $row->ltideploymentid);
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
     * Key of a target in the result of provider_states().
     *
     * @param \stdClass $target
     * @return string
     */
    public static function state_key(\stdClass $target): string {
        return $target->sourcekind . '/' . $target->sourceid . '/' . $target->userid;
    }

    /**
     * Course of a course module (cached per run).
     *
     * @param int $cmid
     * @return int 0 when the module does not exist
     */
    public static function course_of_cm(int $cmid): int {
        global $DB;
        self::fresh();
        if (!array_key_exists($cmid, self::$cmcourses)) {
            self::$cmcourses[$cmid] = (int) $DB->get_field('course_modules', 'course', ['id' => $cmid]);
        }
        return self::$cmcourses[$cmid];
    }

    /**
     * Whether a source has completion at all (cached per run).
     *
     * @param string $kind
     * @param int $sourceid
     * @return bool
     */
    private static function source_enabled(string $kind, int $sourceid): bool {
        global $CFG, $DB;
        $key = $kind . '/' . $sourceid;
        self::fresh();
        if (array_key_exists($key, self::$enabled)) {
            return self::$enabled[$key];
        }
        require_once($CFG->libdir . '/completionlib.php');
        $enabled = false;
        if ($kind === self::KIND_CM) {
            $courseid = self::course_of_cm($sourceid);
            if ($courseid) {
                $cm = get_fast_modinfo($courseid)->get_cm($sourceid);
                $enabled = (bool) (new \completion_info($cm->get_course()))->is_enabled($cm);
            }
        } else {
            $course = $DB->get_record('course', ['id' => $sourceid]);
            if ($course) {
                $completion = new \completion_info($course);
                $enabled = $completion->is_enabled() && $completion->has_criteria();
            }
        }
        return self::$enabled[$key] = $enabled;
    }

    /**
     * Provider states of many targets: one query per source and batch.
     *
     * @param \stdClass[] $targets
     * @return array state_key => 1 completed, 0 not completed, null when the source has no completion
     */
    public static function provider_states(array $targets): array {
        global $DB;
        $bysource = [];
        foreach ($targets as $target) {
            $bysource[$target->sourcekind . '/' . $target->sourceid][(int) $target->userid] = true;
        }
        $states = [];
        foreach ($bysource as $source => $users) {
            [$kind, $sourceid] = explode('/', $source);
            $sourceid = (int) $sourceid;
            $userids = array_keys($users);
            if (!self::source_enabled($kind, $sourceid)) {
                foreach ($userids as $userid) {
                    $states["$source/$userid"] = null;
                }
                continue;
            }
            foreach (array_chunk($userids, self::BATCH) as $chunk) {
                [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
                if ($kind === self::KIND_CM) {
                    $complete = $DB->get_records_select_menu(
                        'course_modules_completion',
                        "coursemoduleid = :src AND userid $insql AND completionstate IN (:c1, :c2)",
                        ['src' => $sourceid, 'c1' => COMPLETION_COMPLETE, 'c2' => COMPLETION_COMPLETE_PASS] + $params,
                        '',
                        'userid, completionstate'
                    );
                } else {
                    $complete = $DB->get_records_select_menu(
                        'course_completions',
                        "course = :src AND userid $insql AND timecompleted > 0",
                        ['src' => $sourceid] + $params,
                        '',
                        'userid, timecompleted'
                    );
                }
                foreach ($chunk as $userid) {
                    $states["$source/$userid"] = isset($complete[$userid]) ? 1 : 0;
                }
            }
        }
        return $states;
    }

    /**
     * Provider state of one target: 1 completed, 0 not completed, null when the source has no completion at all.
     *
     * @param string $kind
     * @param int $sourceid cmid or course id
     * @param int $userid
     * @return int|null
     */
    public static function provider_state(string $kind, int $sourceid, int $userid): ?int {
        $target = (object) ['sourcekind' => $kind, 'sourceid' => $sourceid, 'userid' => $userid];
        return self::provider_states([$target])[self::state_key($target)];
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
        // After an account merge one user can hold several rows of the same resource: the latest launch wins.
        $rows = $DB->get_fieldset_sql(
            'SELECT sourceid FROM {enrol_lti_users} WHERE userid = :userid AND toolid = :toolid
           ORDER BY lastaccess DESC, id DESC',
            ['userid' => $userid, 'toolid' => $toolid]
        );
        $sub = $rows ? reset($rows) : null;
        return ($sub === false || $sub === null || $sub === '') ? null : (string) $sub;
    }
}
