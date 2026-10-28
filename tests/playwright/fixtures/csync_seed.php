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
 * E2E seed extension of local_lticompletionsync (two-site stack, see tests/playwright/README.md).
 *
 * Adds own fixtures next to the stack's seed (never changes the stack's own courses):
 *   --stage=platform1  LTI 1.3 tool type on the platform (or --reusetype=ID of the stack's type)
 *   --stage=tool       published page (completion on view) + registration/deployment + sender connection
 *   --stage=platform2  course, learners, external tool activity (manual completion, optional grade) + receiver
 *   --stage=revoke     provider-side revocation (override to incomplete) of the published page for a user
 *   --stage=deliver    deliver due outbox rows now (instead of waiting for cron)
 *   --stage=state      native completion, override, grade and receiver row of a platform activity (JSON)
 *   --stage=replay     duplicate (same version) and out-of-order (older version) messages of the tool side
 *   --stage=cron       completion and plugin scheduled tasks on this site
 *   --stage=storm      tool side: K new versions of a learner's row, each sent concurrently (curl_multi, separate
 *                      php-fpm workers on the platform) with duplicates and byte-identical replays, in shuffled order
 *   --stage=applied    platform side: applied/revoked versions of an activity in journal order, receiver row, state
 *   --stage=resync     tool side: force resync of the scenario's connection (new version for every target)
 *   --stage=retrynow   tool side: retry rows of the scenario's connection due now
 *   --stage=requeue    tool side: requeue the dead letters of the scenario's connection (administrator action)
 *   --stage=outrows    tool side: outbox rows of the scenario with their journal "sent" counts
 *   --stage=sender     tool side: sender options of the scenario's connection (--agsprogress=0|1)
 *   --stage=agsstate   platform side: grade items, line items, grades of the scenario's activity, completion
 *   --stage=report     outcome report of this site (JSON, as cli/sync.php --report)
 * Output: one JSON object on the last line.
 *
 * Usage: LTI_WWWROOT=<site> php csync_seed.php --root=<moodle tree> --stage=... [options]
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing,moodle.Files.MoodleInternal.MoodleInternalGlobalState -- standalone CLI script loading the config.php of a given tree.

define('CLI_SCRIPT', true);

$root = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--root=') === 0) {
        $root = substr($arg, 7);
    }
}
if (!$root || !is_file($root . '/config.php')) {
    fwrite(STDERR, "--root=<moodle tree> is required\n");
    exit(2);
}
require($root . '/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/mod/lti/locallib.php');
require_once($CFG->dirroot . '/course/modlib.php');

use enrol_lti\local\ltiadvantage\entity\application_registration;
use enrol_lti\local\ltiadvantage\repository\application_registration_repository;
use enrol_lti\local\ltiadvantage\repository\deployment_repository;
use local_lticompletionsync\local\connection;
use local_lticompletionsync\local\protocol;

[$o] = cli_get_params([
    'root' => '', 'stage' => '', 'scenario' => 'x', 'toolbase' => '', 'reusetype' => 0, 'issuer' => '', 'clientid' => '',
    'deploymentid' => '', 'endpoint' => '', 'typeid' => 0, 'loginurl' => '', 'uuid' => '', 'learners' => '', 'grade' => '',
    'username' => '', 'cmid' => 0, 'action' => '', 'key' => '', 'from' => 0, 'corr' => '', 'versions' => 6,
    'agsprogress' => '', 'hours' => 1,
], []);
\core\session\manager::set_user(get_admin());
$scenario = clean_param($o['scenario'], PARAM_ALPHANUMEXT);
$out = [];

/**
 * Create or update a user with a known password.
 *
 * @param string $username
 * @return stdClass
 */
function csync_user(string $username): stdClass {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/user/lib.php');
    $password = 'E2e!Pass-' . $username . '-2026';
    $user = $DB->get_record('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0]);
    if (!$user) {
        $id = user_create_user((object) ['username' => $username, 'password' => $password, 'firstname' => 'Csync',
            'lastname' => ucfirst($username), 'email' => $username . '@example.com', 'auth' => 'manual', 'confirmed' => 1,
            'mnethostid' => $CFG->mnet_localhost_id], true, false);
        $user = $DB->get_record('user', ['id' => $id]);
    } else {
        update_internal_user_password($user, $password);
    }
    return $user;
}

/**
 * The sender connection of a scenario (tool side), found through its published course.
 *
 * @param string $scenario
 * @return stdClass
 */
function csync_sender(string $scenario): stdClass {
    global $DB;
    $toolid = (int) $DB->get_field_sql(
        "SELECT t.id FROM {enrol_lti_tools} t JOIN {enrol} e ON e.id = t.enrolid
           JOIN {course} c ON c.id = e.courseid WHERE c.shortname = ?",
        ['CSYNC-T-' . $scenario]
    );
    $row = $DB->get_record_sql(
        "SELECT * FROM {local_lticompletionsync_out} WHERE toolid = ? ORDER BY id",
        [$toolid],
        IGNORE_MULTIPLE
    );
    if ($row) {
        return connection::get((int) $row->connectionid);
    }
    $dep = $DB->get_field_sql(
        "SELECT DISTINCT rl.ltideploymentid FROM {enrol_lti_resource_link} rl WHERE rl.resourceid = ?",
        [$toolid],
        IGNORE_MULTIPLE
    );
    return $DB->get_record(
        connection::TABLE,
        ['role' => connection::ROLE_SENDER, 'deploymentid' => (int) $dep],
        '*',
        MUST_EXIST
    );
}

/**
 * Outbox rows of a scenario (tool side).
 *
 * @param string $scenario
 * @return stdClass[]
 */
function csync_outrows(string $scenario): array {
    global $DB;
    return $DB->get_records_sql(
        "SELECT o.* FROM {local_lticompletionsync_out} o
           JOIN {enrol_lti_tools} t ON t.id = o.toolid JOIN {enrol} e ON e.id = t.enrolid
           JOIN {course} c ON c.id = e.courseid
          WHERE c.shortname = ? ORDER BY o.id",
        ['CSYNC-T-' . $scenario]
    );
}

/**
 * Recreate a course by short name.
 *
 * @param string $shortname
 * @return stdClass
 */
function csync_course(string $shortname): stdClass {
    global $DB;
    if ($old = $DB->get_record('course', ['shortname' => $shortname])) {
        delete_course($old, false);
    }
    return create_course((object) ['fullname' => 'Completion sync ' . $shortname, 'shortname' => $shortname, 'category' => 1,
        'format' => 'topics', 'numsections' => 1, 'enablecompletion' => 1, 'showcompletionconditions' => 1]);
}

switch ($o['stage']) {
    case 'platform1':
        if ((int) $o['reusetype'] > 0) {
            $type = $DB->get_record('lti_types', ['id' => (int) $o['reusetype']], '*', MUST_EXIST);
        } else {
            $name = 'CSYNC tool ' . $scenario;
            foreach ($DB->get_records('lti_types', ['name' => $name]) as $old) {
                lti_delete_type($old->id);
            }
            $base = rtrim($o['toolbase'], '/');
            $typeid = lti_add_type((object) ['state' => LTI_TOOL_STATE_CONFIGURED], (object) [
                'lti_typename' => $name, 'lti_toolurl' => "$base/enrol/lti/launch.php", 'lti_ltiversion' => LTI_VERSION_1P3,
                'lti_keytype' => LTI_JWK_KEYSET, 'lti_publickeyset' => "$base/enrol/lti/jwks.php",
                'lti_initiatelogin' => "$base/enrol/lti/login.php",
                'lti_redirectionuris' => "$base/enrol/lti/launch.php\n$base/enrol/lti/launch_deeplink.php",
                'lti_coursevisible' => LTI_COURSEVISIBLE_PRECONFIGURED, 'lti_sendname' => LTI_SETTING_ALWAYS,
                'lti_sendemailaddr' => LTI_SETTING_ALWAYS, 'lti_acceptgrades' => LTI_SETTING_ALWAYS,
                'lti_launchcontainer' => LTI_LAUNCH_CONTAINER_REPLACE_MOODLE_WINDOW, 'lti_customparameters' => '',
                'ltiservice_gradesynchronization' => 2, 'ltiservice_memberships' => 1,
            ]);
            $type = $DB->get_record('lti_types', ['id' => $typeid], '*', MUST_EXIST);
        }
        $out = ['typeid' => (int) $type->id, 'clientid' => $type->clientid, 'deploymentid' => (string) $type->id,
            'issuer' => $CFG->wwwroot, 'jwks' => "$CFG->wwwroot/mod/lti/certs.php",
            'auth' => "$CFG->wwwroot/mod/lti/auth.php", 'token' => "$CFG->wwwroot/mod/lti/token.php",
            'endpoint' => protocol::endpoint_url()];
        break;

    case 'tool':
        \core\plugininfo\enrol::enable_plugin('lti', 1);
        \core\plugininfo\auth::enable_plugin('lti', 1);
        set_config('allowframembedding', 1);
        // The stack resolves the test hosts to 127.0.0.1: allowed for this test lab only.
        set_config('allowprivatetargets', 1, 'local_lticompletionsync');
        $issuer = rtrim($o['issuer'], '/');
        $repo = new application_registration_repository();
        $reg = $repo->find_by_platform($issuer, $o['clientid']);
        if (!$reg) {
            $reg = $repo->save(application_registration::create(
                'CSYNC platform ' . $scenario,
                bin2hex(random_bytes(30)),
                new moodle_url($issuer),
                $o['clientid'],
                new moodle_url("$issuer/mod/lti/auth.php"),
                new moodle_url("$issuer/mod/lti/certs.php"),
                new moodle_url("$issuer/mod/lti/token.php")
            ));
        }
        $deprepo = new deployment_repository();
        $dep = $deprepo->find_by_registration($reg->get_id(), $o['deploymentid']);
        if (!$dep) {
            $dep = $deprepo->save($reg->add_tool_deployment('CSYNC deployment ' . $scenario, $o['deploymentid']));
        }
        $course = csync_course('CSYNC-T-' . $scenario);
        $page = (object) ['modulename' => 'page', 'module' => $DB->get_field('modules', 'id', ['name' => 'page']),
            'course' => $course->id, 'section' => 1, 'visible' => 1, 'name' => 'Sync page ' . $scenario,
            'introeditor' => ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0],
            'page' => ['text' => 'Reading this page completes it.', 'format' => FORMAT_HTML, 'itemid' => 0],
            'display' => 5, 'printintro' => 0, 'printlastmodified' => 0,
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => 1, 'visibleoncoursepage' => 1];
        $page = add_moduleinfo($page, $course);
        $plugin = enrol_get_plugin('lti');
        $instanceid = $plugin->add_instance($course, [
            'name' => 'Sync page ' . $scenario, 'courseid' => $course->id,
            'contextid' => context_module::instance($page->coursemodule)->id, 'cmid' => $page->coursemodule,
            'status' => ENROL_INSTANCE_ENABLED, 'ltiversion' => 'LTI-1p3',
            'roleinstructor' => $DB->get_field('role', 'id', ['shortname' => 'teacher']),
            'rolelearner' => $DB->get_field('role', 'id', ['shortname' => 'student']),
            'provisioningmodelearner' => 1, 'provisioningmodeinstructor' => 1, 'gradesync' => 0, 'gradesynccompletion' => 0,
            'membersync' => 0, 'membersyncmode' => 1, 'maxenrolled' => 0,
        ]);
        $tool = $DB->get_record('enrol_lti_tools', ['enrolid' => $instanceid], '*', MUST_EXIST);
        $existing = $DB->get_record(connection::TABLE, ['role' => connection::ROLE_SENDER, 'deploymentid' => $dep->get_id()]);
        connection::save((object) ['id' => $existing->id ?? 0, 'role' => connection::ROLE_SENDER, 'enabled' => 1,
            'deploymentid' => $dep->get_id(), 'endpointurl' => $o['endpoint'], 'agsprogress' => 0]);
        $out = ['uuid' => $tool->uuid, 'courseid' => (int) $course->id, 'pagecmid' => (int) $page->coursemodule,
            'regid' => (int) $reg->get_id(), 'toolid' => (int) $tool->id,
            'loginurl' => "$CFG->wwwroot/enrol/lti/login.php?id=" . $reg->get_uniqueid(), 'deploymentrow' => $dep->get_id()];
        break;

    case 'platform2':
        $typeid = (int) $o['typeid'];
        if ($o['loginurl'] !== '') {
            $DB->set_field('lti_types_config', 'value', $o['loginurl'], ['typeid' => $typeid, 'name' => 'initiatelogin']);
        }
        set_config('allowframembedding', 1);
        $course = csync_course('CSYNC-P-' . $scenario);
        $learners = [];
        foreach (array_filter(explode(',', $o['learners'])) as $username) {
            $user = csync_user(clean_param($username, PARAM_USERNAME));
            $plugin = enrol_get_plugin('manual');
            $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
            $plugin->enrol_user($instance, $user->id, $DB->get_field('role', 'id', ['shortname' => 'student']));
            $learners[] = ['username' => $user->username, 'password' => 'E2e!Pass-' . $user->username . '-2026',
                'id' => (int) $user->id];
        }
        $mi = (object) ['modulename' => 'lti', 'module' => $DB->get_field('modules', 'id', ['name' => 'lti'], MUST_EXIST),
            'course' => $course->id, 'section' => 1, 'visible' => 1, 'name' => 'Sync activity ' . $scenario,
            'introeditor' => ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0], 'showdescription' => 0,
            'typeid' => $typeid, 'toolurl' => '', 'securetoolurl' => '', 'instructorcustomparameters' => 'id=' . $o['uuid'],
            'launchcontainer' => LTI_LAUNCH_CONTAINER_REPLACE_MOODLE_WINDOW, 'instructorchoicesendname' => 1,
            'instructorchoicesendemailaddr' => 1, 'instructorchoiceacceptgrades' => 1, 'instructorchoiceallowroster' => 1,
            'instructorchoiceallowsetting' => 0, 'grade' => 100, 'resourcekey' => '', 'password' => '',
            'cmidnumber' => 'csync-' . $scenario, 'visibleoncoursepage' => 1, 'completion' => COMPLETION_TRACKING_MANUAL];
        $cm = add_moduleinfo($mi, $course);
        if ($o['grade'] !== '') {
            foreach ($learners as $learner) {
                grade_update(
                    'mod/lti',
                    $course->id,
                    'mod',
                    'lti',
                    $cm->instance,
                    0,
                    [$learner['id'] => (object) ['userid' => $learner['id'], 'rawgrade' => (float) $o['grade']]]
                );
            }
        }
        $existing = $DB->get_record(connection::TABLE, ['role' => connection::ROLE_RECEIVER, 'ltitypeid' => $typeid]);
        connection::save((object) ['id' => $existing->id ?? 0, 'role' => connection::ROLE_RECEIVER, 'enabled' => 1,
            'ltitypeid' => $typeid, 'automanage' => 1, 'lockdown' => 1, 'conflictpolicy' => connection::POLICY_PROVIDER]);
        $out = ['courseid' => (int) $course->id, 'cmid' => (int) $cm->coursemodule, 'instance' => (int) $cm->instance,
            'viewurl' => "$CFG->wwwroot/mod/lti/view.php?id={$cm->coursemodule}",
            'courseurl' => "$CFG->wwwroot/course/view.php?id={$course->id}", 'learners' => $learners];
        break;

    case 'revoke':
        $course = $DB->get_record('course', ['shortname' => 'CSYNC-T-' . $scenario], '*', MUST_EXIST);
        $cm = get_fast_modinfo($course)->get_cm((int) $o['cmid']);
        $completion = new completion_info($course);
        $count = 0;
        foreach ($DB->get_records('course_modules_completion', ['coursemoduleid' => $cm->id]) as $row) {
            $completion->update_state($cm, COMPLETION_INCOMPLETE, $row->userid, true);
            $count++;
        }
        $out = ['revoked' => $count];
        break;

    case 'deliver':
        \core\cron::setup_user();
        $out = ['delivered' => (new \local_lticompletionsync\local\sender\dispatcher())->deliver_due()];
        break;

    case 'state':
        $cmid = (int) $o['cmid'];
        $user = $DB->get_record('user', ['username' => $o['username']], '*', MUST_EXIST);
        $courseid = $DB->get_field('course_modules', 'course', ['id' => $cmid], MUST_EXIST);
        $cm = get_fast_modinfo($courseid)->get_cm($cmid);
        $completion = new completion_info(get_course($courseid));
        $data = $completion->get_data($cm, false, $user->id);
        $grades = grade_get_grades($courseid, 'mod', 'lti', $cm->instance, $user->id);
        $grade = $grades->items[0]->grades[$user->id]->grade ?? null;
        $rcv = $DB->get_record('local_lticompletionsync_rcv', ['cmid' => $cmid, 'userid' => $user->id]);
        $out = ['state' => (int) $data->completionstate, 'overrideby' => $data->overrideby, 'grade' => $grade,
            'gradeitems' => $DB->count_records('grade_items', ['courseid' => $courseid]),
            'rcv' => $rcv ? ['status' => $rcv->status, 'remoteversion' => (int) $rcv->remoteversion,
                'appliedversion' => (int) $rcv->appliedversion, 'reason' => $rcv->reason] : null,
            'canself' => has_capability('moodle/course:togglecompletion', context_module::instance($cmid), $user)];
        break;

    case 'replay':
        $course = $DB->get_record('course', ['shortname' => 'CSYNC-T-' . $scenario], '*', MUST_EXIST);
        $out = ['results' => []];
        foreach ($DB->get_records('local_lticompletionsync_out', ['sourcekind' => 'cm']) as $row) {
            if ((int) $DB->get_field('course_modules', 'course', ['id' => $row->sourceid]) !== (int) $course->id) {
                continue;
            }
            $con = connection::get((int) $row->connectionid);
            $rl = $DB->get_record('enrol_lti_resource_link', ['id' => $row->resourcelinkid]);
            $sub = \local_lticompletionsync\local\sender\source::subject((int) $row->userid, (int) $row->toolid);
            $channel = new \local_lticompletionsync\local\sender\channel();
            foreach ([(int) $row->version, (int) $row->version - 1] as $version) {
                if ($version < 1) {
                    continue;
                }
                $answer = $channel->exchange($con, ['type' => protocol::TYPE_COMPLETION,
                    'resource_link_id' => (string) $rl->resourcelinkid, 'user' => $sub,
                    'state' => protocol::state_name($version === (int) $row->version ? (int) $row->desiredstate :
                        1 - (int) $row->desiredstate),
                    'version' => $version, 'timestamp' => (int) $row->statetime, 'correlation_id' => $row->correlationid]);
                $out['results'][] = ['version' => $version, 'status' => $answer['result']['status'] ?? null,
                    'reason' => $answer['result']['reason'] ?? null, 'error' => $answer['error']];
            }
        }
        break;

    case 'cron':
        \core\cron::setup_user();
        ob_start();
        foreach (
            ['\core\task\completion_regular_task', '\local_lticompletionsync\task\sweep_task',
            '\local_lticompletionsync\task\full_reconcile_task'] as $class
        ) {
            (new $class())->execute();
        }
        // Only this plugin's ad hoc tasks: other components' queues are left alone.
        foreach (['dispatch_task', 'reassert_task', 'lockdown_task', 'reconcile_task'] as $name) {
            foreach (\core\task\manager::get_adhoc_tasks('\\local_lticompletionsync\\task\\' . $name) as $task) {
                $task->execute();
                $DB->delete_records('task_adhoc', ['id' => $task->get_id()]);
            }
        }
        ob_end_clean();
        $out = ['cron' => 'ok'];
        break;

    case 'pseudo':
        // Pseudonymisation policy of ltisource_ltipseudonym for ONE tool type (the scenario's own type).
        if (!class_exists('\\ltisource_ltipseudonym\\local\\policy_manager')) {
            cli_error('ltisource_ltipseudonym is not installed');
        }
        $typeid = (int) $o['typeid'];
        $adminid = (int) get_admin()->id;
        if ($o['action'] === 'set') {
            \ltisource_ltipseudonym\local\policy_manager::save($typeid, 0, 0, (object) [
                'pseudotype' => 'random_persisted', 'scopetype' => 'deployment', 'allowoverride' => 1,
            ], $adminid);
        } else if ($o['action'] === 'rotate') {
            $out['rotated'] = \ltisource_ltipseudonym\local\migration_service::rotate($typeid, $adminid);
        } else if ($o['action'] === 'clear') {
            \ltisource_ltipseudonym\local\policy_manager::delete($typeid, 0, 0, $adminid);
        }
        $eff = \ltisource_ltipseudonym\local\policy_manager::resolve($typeid);
        $out += ['pseudotype' => $eff->pseudotype, 'active' => \ltisource_ltipseudonym\local\policy_manager::is_active($eff),
            'mappings' => $DB->count_records('ltisource_ltipseudonym_map', ['typeid' => $typeid, 'status' => 'active']),
            'misses' => $DB->count_records('ltisource_ltipseudonym_aud', ['typeid' => $typeid,
                'action' => 'service_pseudonym_miss'])];
        break;

    case 'toolrows':
        // Tool side view of the scenario: LTI subjects of the published page's users and their outbox rows.
        $toolid = (int) $DB->get_field_sql(
            "SELECT t.id FROM {enrol_lti_tools} t JOIN {enrol} e ON e.id = t.enrolid
            JOIN {course} c ON c.id = e.courseid WHERE c.shortname = ? AND t.contextid <> ?",
            ['CSYNC-T-' . $scenario, 0]
        );
        $out = ['subs' => array_values($DB->get_fieldset_select('enrol_lti_users', 'sourceid', 'toolid = ?', [$toolid])),
            'rows' => array_values(array_map(
                fn($r) => ['status' => $r->status, 'version' => (int) $r->version,
                'lasterror' => $r->lasterror, 'correlationid' => $r->correlationid],
                $DB->get_records('local_lticompletionsync_out', ['toolid' => $toolid], 'id')
            ))];
        break;

    case 'journal':
        // Journal entries of this site since --from (optionally one correlation id).
        $params = ['from' => (int) $o['from']];
        $where = 'timecreated >= :from';
        if ($o['corr'] !== '') {
            $where .= ' AND correlationid = :corr';
            $params['corr'] = $o['corr'];
        }
        $out = ['entries' => array_values(array_map(
            fn($r) => ['event' => $r->eventname, 'detail' => $r->detail,
            'corr' => $r->correlationid, 'userid' => (int) $r->userid],
            $DB->get_records_select('local_lticompletionsync_log', $where, $params, 'id')
        )),
            'logstore' => (int) $DB->count_records_select(
                'logstore_standard_log',
                $DB->sql_like('eventname', ':ev') . ' AND timecreated >= :from',
                ['ev' => '%local_lticompletionsync%',
                'from' => (int) $o['from']]
            )];
        break;

    case 'diag':
        // What tool_ltidiagnostics shows for a connection (CS-G6 evidence): timeline sources and items of this plugin.
        if (!class_exists('\\tool_ltidiagnostics\\local\\inventory')) {
            $out = ['installed' => false];
            break;
        }
        $conn = (new \tool_ltidiagnostics\local\inventory())->get_connection($o['key']);
        $from = (int) $o['from'];
        $tl = (new \tool_ltidiagnostics\local\timeline())->build(
            $conn,
            context_system::instance(),
            ['from' => $from, 'to' => time() + 60],
            time()
        );
        $items = $tl['items'] ?? [];
        $json = json_encode($tl);
        $mine = array_values(array_filter($items, fn($i) => strpos(json_encode($i), 'lticompletionsync') !== false));
        $out = ['installed' => true, 'items' => count($items),
            'sources' => array_count_values(array_column($items, 'source')),
            'plugin_items' => array_map(fn($i) => ['source' => $i['source'], 'label' => (string) ($i['label'] ?? '')], $mine),
            'correlation_found' => $o['corr'] !== '' && strpos($json, $o['corr']) !== false,
            'logstore_events_whitelist' => \tool_ltidiagnostics\local\logstore_source::EVENTS];
        break;

    case 'storm':
        // K new provider versions of the first row of the scenario, alternating states; each version is sent twice
        // with different jtis (duplicate delivery) and once more byte-identical (replay); all requests go out at once.
        $rows = csync_outrows($scenario);
        $row = reset($rows);
        $con = connection::get((int) $row->connectionid);
        $reg = $DB->get_record('enrol_lti_app_registration', ['id' => $con->registrationid], '*', MUST_EXIST);
        $dep = $DB->get_record('enrol_lti_deployment', ['id' => $con->deploymentid], '*', MUST_EXIST);
        $rl = $DB->get_record('enrol_lti_resource_link', ['id' => $row->resourcelinkid], '*', MUST_EXIST);
        $sub = \local_lticompletionsync\local\sender\source::subject((int) $row->userid, (int) $row->toolid);
        $k = max(2, (int) $o['versions']);
        $base = (int) $row->version;
        $time = max(time(), (int) $row->statetime + 1);
        $requests = [];
        $state = (int) $row->desiredstate;
        for ($i = 1; $i <= $k; $i++) {
            $state = 1 - $state;
            $msg = ['v' => 1, 'deployment_id' => $dep->deploymentid, 'type' => protocol::TYPE_COMPLETION,
                'resource_link_id' => (string) $rl->resourcelinkid, 'user' => $sub, 'state' => protocol::state_name($state),
                'activity_progress' => $state ? 'Completed' : 'Initialized', 'version' => $base + $i,
                'timestamp' => $time + $i, 'correlation_id' => \core\uuid::generate(), 'source' => 'cm'];
            foreach (['first', 'duplicate'] as $kind) {
                $now = time();
                $jws = \local_lticompletionsync\local\keys::tool_sign(['iss' => $reg->clientid, 'sub' => $reg->clientid,
                    'aud' => $con->endpointurl, 'iat' => $now, 'exp' => $now + 120, 'jti' => bin2hex(random_bytes(16)),
                    protocol::CLAIM => $msg]);
                $requests[] = ['version' => $base + $i, 'kind' => $kind, 'jws' => $jws];
                if ($kind === 'first') {
                    $requests[] = ['version' => $base + $i, 'kind' => 'replay', 'jws' => $jws];
                }
            }
        }
        shuffle($requests);
        // The tool's own row now holds the newest version (as if the changes had happened), due for normal delivery.
        $DB->update_record('local_lticompletionsync_out', (object) ['id' => $row->id, 'version' => $base + $k,
            'desiredstate' => $state, 'statetime' => $time + $k, 'status' => 'pending', 'attempts' => 0, 'nextattempt' => 0,
            'correlationid' => \core\uuid::generate()]);
        $multi = curl_multi_init();
        $handles = [];
        foreach ($requests as $i => $request) {
            $ch = curl_init($con->endpointurl);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $request['jws'],
                CURLOPT_HTTPHEADER => ['Content-Type: application/jwt'], CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 120, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
            curl_multi_add_handle($multi, $ch);
            $handles[$i] = $ch;
        }
        $t = microtime(true);
        do {
            $status = curl_multi_exec($multi, $active);
            if ($active) {
                curl_multi_select($multi, 1.0);
            }
        } while ($active && $status === CURLM_OK);
        $answers = [];
        foreach ($handles as $i => $ch) {
            $body = (string) curl_multi_getcontent($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $claim = null;
            if ($http === 200) {
                $claim = \local_lticompletionsync\local\keys::peek($body)[1][protocol::CLAIM] ?? null;
            }
            $answers[] = ['version' => $requests[$i]['version'], 'kind' => $requests[$i]['kind'], 'http' => $http,
                'status' => $claim['status'] ?? null, 'reason' => $claim['reason'] ?? null,
                'error' => $http === 200 ? null : (json_decode($body, true)['error'] ?? curl_error($ch))];
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);
        $out = ['base' => $base, 'final' => $base + $k, 'finalstate' => $state, 'requests' => count($requests),
            'seconds' => round(microtime(true) - $t, 2), 'answers' => $answers];
        break;

    case 'applied':
        $cmid = (int) $o['cmid'];
        $user = $DB->get_record('user', ['username' => $o['username']], '*', MUST_EXIST);
        $courseid = $DB->get_field('course_modules', 'course', ['id' => $cmid], MUST_EXIST);
        $cm = get_fast_modinfo($courseid)->get_cm($cmid);
        $data = (new completion_info(get_course($courseid)))->get_data($cm, false, $user->id);
        $seq = $DB->get_records_select(
            'local_lticompletionsync_log',
            'cmid = :cmid AND userid = :u AND eventname IN (:a, :r)',
            ['cmid' => $cmid, 'u' => $user->id, 'a' => 'completion_applied', 'r' => 'completion_revoked'],
            'id',
            'id, eventname, version'
        );
        $rcv = $DB->get_record('local_lticompletionsync_rcv', ['cmid' => $cmid, 'userid' => $user->id]);
        $out = ['state' => (int) $data->completionstate,
            'sequence' => array_values(array_map(fn($r) => ['event' => $r->eventname, 'version' => (int) $r->version], $seq)),
            'rcv' => $rcv ? ['status' => $rcv->status, 'remoteversion' => (int) $rcv->remoteversion,
                'appliedversion' => (int) $rcv->appliedversion, 'remotestate' => (int) $rcv->remotestate] : null,
            'completionevents' => $DB->count_records_select(
                'logstore_standard_log',
                'eventname = :e AND contextinstanceid = :cmid AND relateduserid = :u',
                ['e' => '\\core\\event\\course_module_completion_updated', 'cmid' => $cmid, 'u' => $user->id]
            ),
            'replays' => $DB->count_records_select('local_lticompletionsync_jti', '1 = 1')];
        break;

    case 'resync':
        \core\cron::setup_user();
        $con = csync_sender($scenario);
        \local_lticompletionsync\local\sender\source::reset_caches();
        $out = ['versions' => \local_lticompletionsync\local\sender\reconciler::full((int) $con->id, true)];
        break;

    case 'retrynow':
        $con = csync_sender($scenario);
        $out = ['due' => \local_lticompletionsync\local\sender\outbox::retry_now((int) $con->id)];
        break;

    case 'requeue':
        $con = csync_sender($scenario);
        $out = ['requeued' => \local_lticompletionsync\local\sender\outbox::requeue_connection((int) $con->id)];
        break;

    case 'outrows':
        $rows = csync_outrows($scenario);
        $out = ['rows' => array_values(array_map(function ($r) use ($DB) {
            return ['id' => (int) $r->id, 'userid' => (int) $r->userid, 'status' => $r->status, 'version' => (int) $r->version,
                'ackversion' => (int) $r->ackversion, 'attempts' => (int) $r->attempts, 'lasterror' => $r->lasterror,
                'agsstatus' => $r->agsstatus, 'correlationid' => $r->correlationid,
                'sent' => $DB->count_records('local_lticompletionsync_log', ['correlationid' => $r->correlationid,
                    'eventname' => 'sent'])];
        }, $rows))];
        break;

    case 'sender':
        $con = csync_sender($scenario);
        if ($o['agsprogress'] !== '') {
            $DB->set_field(connection::TABLE, 'agsprogress', (int) $o['agsprogress'], ['id' => $con->id]);
        }
        if ($o['action'] === 'resetpartner') {
            $DB->set_field(connection::TABLE, 'partnerstatus', connection::PARTNER_UNKNOWN, ['id' => $con->id]);
        }
        $con = connection::get((int) $con->id);
        $out = ['id' => (int) $con->id, 'agsprogress' => (int) $con->agsprogress, 'partnerstatus' => $con->partnerstatus,
            'level' => \local_lticompletionsync\local\status_report::level($con)];
        break;

    case 'agsstate':
        $cmid = (int) $o['cmid'];
        $user = $DB->get_record('user', ['username' => $o['username']], '*', MUST_EXIST);
        $courseid = (int) $DB->get_field('course_modules', 'course', ['id' => $cmid], MUST_EXIST);
        $instance = (int) $DB->get_field('course_modules', 'instance', ['id' => $cmid]);
        $cm = get_fast_modinfo($courseid)->get_cm($cmid);
        $data = (new completion_info(get_course($courseid)))->get_data($cm, false, $user->id);
        $items = [];
        // Items of the activity and every item behind a line item of the course (mod_lti creates tool line items as
        // grade items of its own kind).
        $gis = $DB->get_records('grade_items', ['courseid' => $courseid, 'itemtype' => 'mod', 'itemmodule' => 'lti'], 'id');
        if ($DB->get_manager()->table_exists('ltiservice_gradebookservices')) {
            $gids = $DB->get_fieldset_select('ltiservice_gradebookservices', 'gradeitemid', 'courseid = ?', [$courseid]);
            foreach ($gids as $gid) {
                $gis += $DB->get_records('grade_items', ['id' => $gid]);
            }
        }
        foreach ($gis as $gi) {
            $gg = $DB->get_record('grade_grades', ['itemid' => $gi->id, 'userid' => $user->id]);
            $items[] = ['id' => (int) $gi->id, 'itemtype' => $gi->itemtype, 'iteminstance' => (int) $gi->iteminstance,
                'itemnumber' => (int) $gi->itemnumber,
                'itemname' => $gi->itemname, 'grademax' => (float) $gi->grademax,
                'grade' => $gg ? ($gg->finalgrade === null ? null : (float) $gg->finalgrade) : 'none',
                'history' => (int) $DB->count_records('grade_grades_history', ['itemid' => $gi->id, 'userid' => $user->id])];
        }
        $lineitems = $DB->get_manager()->table_exists('ltiservice_gradebookservices') ?
            array_values(array_map(
                fn($r) => ['gradeitemid' => (int) $r->gradeitemid, 'tag' => $r->tag,
                'resourceid' => $r->resourceid, 'ltilinkid' => (int) $r->ltilinkid],
                $DB->get_records('ltiservice_gradebookservices', ['courseid' => $courseid])
            )) : [];
        $out = ['instance' => $instance, 'state' => (int) $data->completionstate, 'items' => $items, 'lineitems' => $lineitems];
        break;

    case 'report':
        $out = \local_lticompletionsync\local\status_report::outcomes(max(1, (int) $o['hours']) * HOURSECS);
        break;

    default:
        cli_error('unknown --stage');
}
echo json_encode($out) . "\n";
