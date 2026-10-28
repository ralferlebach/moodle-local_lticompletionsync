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
 * Load test (CS-04), step 1: bulk data on a scratch site.
 *
 * Creates N LTI accounts (users + enrol_lti_users rows on R published resources of one sender connection, each account
 * on --links resources), completion of about half of the relations, and on the platform side one course with N tracked
 * learners and one managed, locked-down external tool activity. Bulk inserts (insert_records); no events.
 *
 * Usage: CSYNC_ROOT=<tree> LTI_DB=.. LTI_DBNAME=.. LTI_BASE=.. php seed.php --users=10000 --resources=100 --links=2
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing,moodle.Files.MoodleInternal.MoodleInternalGlobalState -- dev CLI tool.
define('CLI_SCRIPT', true);
require(getenv('CSYNC_ROOT') . '/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/mod/lti/locallib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/testing/generator/lib.php');
\core\session\manager::set_user(get_admin());

use enrol_lti\local\ltiadvantage\entity\application_registration;
use enrol_lti\local\ltiadvantage\repository\application_registration_repository;
use enrol_lti\local\ltiadvantage\repository\deployment_repository;
use local_lticompletionsync\local\connection;
use local_lticompletionsync\local\receiver\activity_manager;

[$o] = cli_get_params(['users' => 10000, 'resources' => 100, 'links' => 2, 'courses' => 10], []);
$nusers = (int) $o['users'];
$nres = (int) $o['resources'];
$nlinks = max(1, min((int) $o['links'], $nres));
$ncourses = max(1, (int) $o['courses']);
$start = microtime(true);
$gen = new testing_data_generator();
\core\plugininfo\enrol::enable_plugin('lti', 1);
\core\plugininfo\auth::enable_plugin('lti', 1);
$now = time();

// Tool side: registration, deployment, sender connection (inserted directly: the example host does not resolve).
$issuer = 'https://platform.example.org';
$reg = (new application_registration_repository())->save(application_registration::create(
    'Load platform',
    bin2hex(random_bytes(30)),
    new moodle_url($issuer),
    'load-client-id',
    new moodle_url("$issuer/mod/lti/auth.php"),
    new moodle_url("$issuer/mod/lti/certs.php"),
    new moodle_url("$issuer/mod/lti/token.php")
));
$dep = (new deployment_repository())->save($reg->add_tool_deployment('Load deployment', '42'));
$conid = $DB->insert_record(connection::TABLE, (object) ['role' => connection::ROLE_SENDER, 'enabled' => 1,
    'registrationid' => $reg->get_id(), 'deploymentid' => $dep->get_id(), 'issuer' => $issuer, 'clientid' => 'load-client-id',
    'endpointurl' => "$issuer/local/lticompletionsync/endpoint.php", 'partnerstatus' => connection::PARTNER_PLUGIN,
    'timecreated' => $now, 'timemodified' => $now]);

// Published resources: pages with manual completion, spread over courses.
$plugin = enrol_get_plugin('lti');
$resources = [];
$courses = [];
for ($c = 0; $c < $ncourses; $c++) {
    $courses[] = $gen->create_course(['enablecompletion' => 1, 'shortname' => 'LOAD-T-' . $c]);
}
for ($r = 0; $r < $nres; $r++) {
    $course = $courses[$r % $ncourses];
    $page = $gen->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
    $instanceid = $plugin->add_instance($course, ['name' => 'Load ' . $r, 'contextid' => context_module::instance($page->cmid)->id,
        'cmid' => $page->cmid, 'status' => ENROL_INSTANCE_ENABLED, 'ltiversion' => 'LTI-1p3',
        'roleinstructor' => $DB->get_field('role', 'id', ['shortname' => 'teacher']),
        'rolelearner' => $DB->get_field('role', 'id', ['shortname' => 'student']), 'provisioningmodelearner' => 1,
        'provisioningmodeinstructor' => 1, 'gradesync' => 0, 'gradesynccompletion' => 0, 'membersync' => 0,
        'membersyncmode' => 1, 'maxenrolled' => 0]);
    $tool = $DB->get_record('enrol_lti_tools', ['enrolid' => $instanceid], '*', MUST_EXIST);
    $rlid = $DB->insert_record('enrol_lti_resource_link', (object) ['resourcelinkid' => 'rl-' . $r,
        'ltideploymentid' => $dep->get_id(), 'resourceid' => $tool->id, 'timecreated' => $now, 'timemodified' => $now]);
    $resources[] = ['toolid' => (int) $tool->id, 'cmid' => (int) $page->cmid, 'rlid' => (int) $rlid];
}

// LTI accounts in batches.
$hash = hash_internal_user_password('Load#2026-not-used', true);
$batch = 1000;
$userids = [];
$relations = 0;
$completions = 0;
for ($offset = 0; $offset < $nusers; $offset += $batch) {
    $rows = [];
    for ($i = $offset; $i < min($nusers, $offset + $batch); $i++) {
        $rows[] = (object) ['auth' => 'lti', 'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id,
            'username' => 'loaduser' . $i, 'password' => $hash, 'firstname' => 'Load', 'lastname' => 'User ' . $i,
            'email' => "loaduser$i@example.org", 'timecreated' => $now, 'timemodified' => $now];
    }
    $DB->insert_records('user', $rows);
    $ids = $DB->get_fieldset_select(
        'user',
        'id',
        $DB->sql_like('username', ':u') . ' AND id > :last',
        ['u' => 'loaduser%', 'last' => $userids ? max($userids) : 0]
    );
    $lurows = [];
    $cmc = [];
    foreach ($ids as $k => $userid) {
        $userids[] = (int) $userid;
        $n = $offset + $k;
        for ($l = 0; $l < $nlinks; $l++) {
            $res = $resources[($n + $l * 37) % $nres];
            $lurows[] = (object) ['userid' => $userid, 'toolid' => $res['toolid'], 'sourceid' => 'sub-' . $userid,
                'ltideploymentid' => $dep->get_id(), 'lastaccess' => $now, 'timecreated' => $now];
            if (($n + $l) % 2 === 0) {
                $cmc[] = (object) ['coursemoduleid' => $res['cmid'], 'userid' => $userid,
                    'completionstate' => COMPLETION_COMPLETE, 'timemodified' => $now - 3600];
            }
        }
    }
    $DB->insert_records('enrol_lti_users', $lurows);
    $DB->insert_records('course_modules_completion', $cmc);
    $relations += count($lurows);
    $completions += count($cmc);
}
// Relation rows user <-> resource link.
$rs = $DB->get_recordset_select('enrol_lti_users', 'ltideploymentid = :d', ['d' => $dep->get_id()], 'id', 'id, toolid');
$bytool = array_column($resources, 'rlid', 'toolid');
$links = [];
foreach ($rs as $lu) {
    $links[] = (object) ['ltiuserid' => $lu->id, 'resourcelinkid' => $bytool[(int) $lu->toolid]];
    if (count($links) >= $batch) {
        $DB->insert_records('enrol_lti_user_resource_link', $links);
        $links = [];
    }
}
$rs->close();
if ($links) {
    $DB->insert_records('enrol_lti_user_resource_link', $links);
}

// Platform side: one course with all accounts as tracked learners and one managed, locked-down activity.
$pcourse = $gen->create_course(['enablecompletion' => 1, 'shortname' => 'LOAD-P']);
$manual = $DB->get_record('enrol', ['courseid' => $pcourse->id, 'enrol' => 'manual'], '*', MUST_EXIST);
$studentrole = (int) $DB->get_field('role', 'id', ['shortname' => 'student']);
$pctx = context_course::instance($pcourse->id);
foreach (array_chunk($userids, $batch) as $chunk) {
    $ue = [];
    $ra = [];
    foreach ($chunk as $userid) {
        $ue[] = (object) ['enrolid' => $manual->id, 'userid' => $userid, 'status' => 0, 'timestart' => 0, 'timeend' => 0,
            'modifierid' => 2, 'timecreated' => $now, 'timemodified' => $now];
        $ra[] = (object) ['roleid' => $studentrole, 'contextid' => $pctx->id, 'userid' => $userid, 'timemodified' => $now,
            'modifierid' => 2, 'component' => '', 'itemid' => 0, 'sortorder' => 0];
    }
    $DB->insert_records('user_enrolments', $ue);
    $DB->insert_records('role_assignments', $ra);
}
$typeid = lti_add_type((object) ['state' => LTI_TOOL_STATE_CONFIGURED], (object) ['lti_typename' => 'Load tool',
    'lti_toolurl' => 'https://tool.example.org/enrol/lti/launch.php', 'lti_ltiversion' => LTI_VERSION_1P3,
    'lti_keytype' => LTI_JWK_KEYSET, 'lti_publickeyset' => 'https://tool.example.org/enrol/lti/jwks.php',
    'lti_initiatelogin' => 'https://tool.example.org/enrol/lti/login.php',
    'lti_redirectionuris' => 'https://tool.example.org/enrol/lti/launch.php', 'lti_coursevisible' => 1]);
$lti = $gen->create_module('lti', ['course' => $pcourse->id, 'typeid' => $typeid, 'toolurl' => '',
    'completion' => COMPLETION_TRACKING_MANUAL]);
connection::save((object) ['role' => connection::ROLE_RECEIVER, 'enabled' => 1, 'ltitypeid' => $typeid, 'automanage' => 0,
    'lockdown' => 1, 'conflictpolicy' => connection::POLICY_PROVIDER]);
activity_manager::set_managed(get_fast_modinfo($pcourse->id)->get_cm($lti->cmid), true);
accesslib_clear_all_caches(true);

echo json_encode(['users' => count($userids), 'resources' => $nres, 'relations' => $relations, 'completions' => $completions,
    'connectionid' => (int) $conid, 'platformcmid' => (int) $lti->cmid, 'seconds' => round(microtime(true) - $start, 1)]) . "\n";
