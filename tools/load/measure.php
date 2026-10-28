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

/**
 * Load test (CS-04), step 2: measure the sender paths and the lockdown verification on the seeded scratch site.
 *
 * Phases (each: wall time, peak memory, DB reads/writes): full reconcile (first run creates one version per completed
 * relation), the queued ad hoc tasks (must be one drain task, not one per version), bounded delivery runs through an
 * in-process transport that signs "applied" answers with this site's platform key (no network), full reconcile and
 * sweep without changes, sweep with 1 % changes, lockdown verification of a course with all accounts, an outage run
 * (bounded requests). EXPLAIN (PostgreSQL: ANALYZE, BUFFERS; MariaDB: ANALYZE) of every query of these paths.
 *
 * Usage: CSYNC_ROOT=<tree> LTI_DB=.. LTI_DBNAME=.. LTI_BASE=.. php measure.php --out=<dir> --platformcmid=N
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing,moodle.Files.MoodleInternal.MoodleInternalGlobalState -- dev CLI tool.
define('CLI_SCRIPT', true);
require(getenv('CSYNC_ROOT') . '/config.php');
require_once($CFG->libdir . '/clilib.php');
\core\cron::setup_user();

use local_lticompletionsync\local\completion_adapter;
use local_lticompletionsync\local\connection;
use local_lticompletionsync\local\keys;
use local_lticompletionsync\local\net\http_client;
use local_lticompletionsync\local\net\http_response;
use local_lticompletionsync\local\protocol;
use local_lticompletionsync\local\sender\channel;
use local_lticompletionsync\local\sender\dispatcher;
use local_lticompletionsync\local\sender\outbox;
use local_lticompletionsync\local\sender\reconciler;
use local_lticompletionsync\local\sender\source;

[$o] = cli_get_params(['out' => '/tmp/csync-load', 'platformcmid' => 0], []);
@mkdir($o['out'], 0777, true);

/**
 * In-process platform: answers every completion with a signed "applied" for the requested version.
 */
class csync_load_platform implements http_client {
    /** @var bool Simulate an outage. */
    public $down = false;
    /** @var int Requests. */
    public $posts = 0;

    /**
     * Answer a request.
     *
     * @param string $url
     * @param string $body
     * @param string $contenttype
     * @return http_response
     */
    public function post(string $url, string $body, string $contenttype): http_response {
        $this->posts++;
        if ($this->down) {
            return new http_response(0, '', '', 'transport_7');
        }
        [, $claims] = keys::peek($body);
        $msg = $claims[protocol::CLAIM];
        $now = time();
        $answer = keys::platform_sign(['iss' => 'https://platform.example.org', 'aud' => $claims['iss'], 'iat' => $now,
            'exp' => $now + 120, 'jti' => bin2hex(random_bytes(16)), protocol::CLAIM => ['v' => 1, 'status' => 'applied',
            'reason' => '', 'version' => $msg['version'] ?? 0, 'appliedversion' => $msg['version'] ?? 0,
            'in_reply_to' => $claims['jti']]]);
        return new http_response(200, $answer, protocol::CONTENT_TYPE);
    }

    /**
     * Key set of this site's platform key.
     *
     * @param string $url
     * @return http_response
     */
    public function get(string $url): http_response {
        return new http_response(200, keys::platform_keyset_json(), 'application/json');
    }
}

$results = ['db' => $CFG->dbtype, 'moodle' => $CFG->release, 'php' => PHP_VERSION, 'phases' => [], 'explain' => []];

/**
 * Measure a phase.
 *
 * @param string $name
 * @param callable $fn returns extra facts (array)
 */
function csync_phase(string $name, callable $fn): void {
    global $DB, $results;
    gc_collect_cycles();
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $base = memory_get_usage();
    $reads = $DB->perf_get_reads();
    $writes = $DB->perf_get_writes();
    $t = microtime(true);
    $extra = $fn();
    $results['phases'][$name] = [
        'seconds' => round(microtime(true) - $t, 2),
        'peak_mb_above_start' => round((memory_get_peak_usage() - $base) / 1048576, 1),
        'peak_mb_total' => round(memory_get_peak_usage() / 1048576, 1),
        'reads' => $DB->perf_get_reads() - $reads,
        'writes' => $DB->perf_get_writes() - $writes,
    ] + $extra;
    cli_writeln(sprintf(
        '%-28s %7.2fs %7.1f MB reads %7d writes %7d %s',
        $name,
        $results['phases'][$name]['seconds'],
        $results['phases'][$name]['peak_mb_above_start'],
        $results['phases'][$name]['reads'],
        $results['phases'][$name]['writes'],
        json_encode($extra)
    ));
}

$con = $DB->get_record(connection::TABLE, ['role' => connection::ROLE_SENDER], '*', MUST_EXIST);
$platform = new csync_load_platform();
source::reset_caches();

csync_phase('full_reconcile_first', function () use ($DB) {
    return ['new_versions' => reconciler::full(), 'outbox_rows' => $DB->count_records(outbox::TABLE)];
});
$results['phases']['full_reconcile_first']['adhoc_tasks'] = $DB->count_records_select(
    'task_adhoc',
    $DB->sql_like('classname', ':c'),
    ['c' => '%local_lticompletionsync%']
);
csync_phase('deliver_all_runs', function () use ($DB, $platform) {
    $runs = [];
    $maxpeak = 0;
    while (
        $DB->count_records_select(
            outbox::TABLE,
            'status IN (:p, :r) AND nextattempt <= :now',
            ['p' => outbox::STATUS_PENDING, 'r' => outbox::STATUS_RETRY, 'now' => time()]
        ) > 0 && count($runs) < 100
    ) {
        $t = microtime(true);
        $n = (new dispatcher(new channel($platform)))->deliver_due();
        $runs[] = ['rows' => $n, 'seconds' => round(microtime(true) - $t, 2)];
        $maxpeak = max($maxpeak, memory_get_peak_usage());
    }
    return ['runs' => count($runs), 'rows_per_run' => array_column($runs, 'rows')[0] ?? 0,
        'max_run_seconds' => $runs ? max(array_column($runs, 'seconds')) : 0,
        'acked' => $DB->count_records(outbox::TABLE, ['status' => outbox::STATUS_ACKED]), 'posts' => $platform->posts];
});
csync_phase('full_reconcile_unchanged', function () {
    source::reset_caches();
    return ['new_versions' => reconciler::full()];
});
csync_phase('sweep_unchanged', function () {
    source::reset_caches();
    return ['new_versions' => reconciler::sweep(time() + 60)];
});
// 1 % of the completed relations are revoked on the tool side (without events, like a course reset).
$revoke = $DB->get_records_select(
    'course_modules_completion',
    'completionstate = 1',
    [],
    'id',
    'id',
    0,
    max(1, (int) round($DB->count_records('course_modules_completion') / 100))
);
[$in, $params] = $DB->get_in_or_equal(array_keys($revoke));
$DB->execute(
    "UPDATE {course_modules_completion} SET completionstate = 0, timemodified = ? WHERE id $in",
    array_merge([time()], $params)
);
csync_phase('sweep_1pct_changed', function () {
    source::reset_caches();
    return ['new_versions' => reconciler::sweep(time() - 30)];
});
csync_phase('outage_run', function () use ($platform, $DB) {
    $platform->down = true;
    $platform->posts = 0;
    $DB->set_field(connection::TABLE, 'partnerstatus', connection::PARTNER_ERROR, []);
    $n = (new dispatcher(new channel($platform)))->deliver_due();
    return ['rows' => $n, 'requests' => $platform->posts,
        'retry_rows' => $DB->count_records(outbox::TABLE, ['status' => outbox::STATUS_RETRY])];
});
if ((int) $o['platformcmid']) {
    $courseid = $DB->get_field('course_modules', 'course', ['id' => (int) $o['platformcmid']]);
    $cm = get_fast_modinfo($courseid)->get_cm((int) $o['platformcmid']);
    csync_phase('lockdown_verification', function () use ($cm, $DB, $courseid) {
        $open = completion_adapter::users_who_can_toggle($cm);
        return ['tracked' => (new completion_info(get_course($courseid)))->get_num_tracked_users(),
            'can_toggle' => $open['count']];
    });
}

/**
 * Short summary of a JSON plan: node types with relation and index names, and the execution time.
 *
 * @param mixed $plan decoded JSON plan (PostgreSQL or MariaDB)
 * @return array
 */
function csync_plan_summary($plan): array {
    $nodes = [];
    $walk = function ($node) use (&$walk, &$nodes) {
        if (!is_array($node)) {
            return;
        }
        if (isset($node['Node Type'])) {
            $nodes[] = trim($node['Node Type'] . ' ' . ($node['Relation Name'] ?? '') . ' ' . ($node['Index Name'] ?? ''));
        }
        if (isset($node['table']['table_name'])) {
            $nodes[] = trim(($node['table']['access_type'] ?? '') . ' ' . $node['table']['table_name'] . ' ' .
                ($node['table']['key'] ?? '') . ' rows=' . ($node['table']['r_rows'] ?? $node['table']['rows'] ?? ''));
        }
        foreach ($node as $child) {
            $walk($child);
        }
    };
    $walk($plan);
    $time = $plan[0]['Execution Time'] ?? ($plan['query_block']['r_total_time_ms'] ?? null);
    return ['nodes' => array_values(array_unique($nodes)), 'execution_ms' => $time];
}

// EXPLAIN of the queries of these paths.
$ispg = $DB->get_dbfamily() === 'postgres';
// One JSON row per query (Moodle's PostgreSQL recordsets use cursors, which cannot wrap EXPLAIN).
$prefix = $ispg ? 'EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) ' : 'ANALYZE FORMAT=JSON ';
$users = array_map('intval', $DB->get_fieldset_select(
    'enrol_lti_users',
    'userid',
    'ltideploymentid = :d ORDER BY id',
    ['d' => $con->deploymentid]
));
$sample = array_slice(array_values(array_unique($users)), 0, source::BATCH);
$cmid = (int) $DB->get_field_sql('SELECT MIN(coursemoduleid) FROM {course_modules_completion}');
$queries = [
    'reconcile_keyset_batch' => ["SELECT url.id AS urlid, rl.id AS resourcelinkid, rl.ltideploymentid, lu.toolid, lu.userid,
            t.contextid FROM {enrol_lti_users} lu JOIN {enrol_lti_tools} t ON t.id = lu.toolid
            JOIN {enrol_lti_user_resource_link} url ON url.ltiuserid = lu.id
            JOIN {enrol_lti_resource_link} rl ON rl.id = url.resourcelinkid AND rl.resourceid = t.id
           WHERE t.ltiversion = :v AND rl.ltideploymentid = :dep AND url.id > :last ORDER BY url.id LIMIT 500",
        ['v' => 'LTI-1p3', 'dep' => $con->deploymentid, 'last' => 5000]],
    'provider_states_cm' => (function () use ($DB, $sample, $cmid) {
        [$in, $p] = $DB->get_in_or_equal($sample, SQL_PARAMS_NAMED, 'u');
        return ["SELECT userid, completionstate FROM {course_modules_completion}
                  WHERE coursemoduleid = :src AND userid $in AND completionstate IN (1, 2)", ['src' => $cmid] + $p];
    })(),
    'outbox_rows_for_targets' => (function () use ($DB, $sample, $con) {
        [$in, $p] = $DB->get_in_or_equal($sample, SQL_PARAMS_NAMED, 'u');
        $rls = $DB->get_fieldset_sql('SELECT DISTINCT resourcelinkid FROM {local_lticompletionsync_out}');
        [$rin, $rp] = $DB->get_in_or_equal($rls ?: [0], SQL_PARAMS_NAMED, 'rl');
        return ["SELECT * FROM {local_lticompletionsync_out} WHERE connectionid = :cid AND resourcelinkid $rin AND userid $in",
            ['cid' => $con->id] + $rp + $p];
    })(),
    'outbox_due' => ["SELECT * FROM {local_lticompletionsync_out} WHERE status IN ('pending', 'retry') AND nextattempt <= :now
        ORDER BY nextattempt ASC, id ASC LIMIT 500", ['now' => time()]],
    'sweep_keyset' => ["SELECT * FROM {local_lticompletionsync_out} WHERE id > :last ORDER BY id ASC LIMIT 500",
        ['last' => 5000]],
    'sweep_changes' => ["SELECT DISTINCT ctx.id AS contextid, cmc.userid FROM {course_modules_completion} cmc
          JOIN {context} ctx ON ctx.instanceid = cmc.coursemoduleid AND ctx.contextlevel = 70
          JOIN {enrol_lti_tools} t ON t.contextid = ctx.id WHERE cmc.timemodified >= :since
         UNION SELECT DISTINCT ctx.id AS contextid, cc.userid FROM {course_completions} cc
          JOIN {context} ctx ON ctx.instanceid = cc.course AND ctx.contextlevel = 50
          JOIN {enrol_lti_tools} t ON t.contextid = ctx.id WHERE cc.timecompleted >= :since2 ORDER BY contextid, userid",
        ['since' => time() - 30, 'since2' => time() - 30]],
    'report_outcomes' => ["SELECT connectionid, eventname, detail, COUNT(1) AS c FROM {local_lticompletionsync_log}
        WHERE timecreated >= :since GROUP BY connectionid, eventname, detail", ['since' => time() - 86400]],
];
foreach ($queries as $label => [$sql, $params]) {
    $t = microtime(true);
    try {
        $rows = $DB->get_records_sql($prefix . $sql, $params);
        $json = json_decode((string) array_values((array) reset($rows))[0], true);
        $results['explain'][$label] = ['ms' => round((microtime(true) - $t) * 1000, 1), 'plan' => $json,
            'summary' => csync_plan_summary($json)];
    } catch (\Throwable $e) {
        $results['explain'][$label] = ['error' => $e->getMessage() . ' ' . ($e->debuginfo ?? '')];
    }
    cli_writeln(sprintf('EXPLAIN %-24s %s', $label, json_encode($results['explain'][$label]['summary'] ??
        $results['explain'][$label]['error'])));
}
$results['tables'] = [];
foreach (
    ['local_lticompletionsync_out', 'local_lticompletionsync_log', 'enrol_lti_users', 'enrol_lti_user_resource_link',
    'course_modules_completion', 'user'] as $table
) {
    $results['tables'][$table] = $DB->count_records($table);
}
file_put_contents($o['out'] . '/results.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo json_encode(['ok' => true, 'out' => $o['out'] . '/results.json']) . "\n";
