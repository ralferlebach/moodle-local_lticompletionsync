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
 * Uninstall check, step 1: plugin state on a scratch site (both roles, lockdowns, journal, tasks, config).
 *
 * Usage: CSYNC_ROOT=<tree> LTI_DB=.. LTI_DBNAME=.. LTI_BASE=.. php seed.php (see run.sh).
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing,moodle.Files.MoodleInternal.MoodleInternalGlobalState -- dev CLI tool.
define('CLI_SCRIPT', true);
require(getenv('CSYNC_ROOT') . '/config.php');
require_once($CFG->dirroot . '/mod/lti/locallib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/testing/generator/lib.php');
\core\session\manager::set_user(get_admin());

use enrol_lti\local\ltiadvantage\entity\application_registration;
use enrol_lti\local\ltiadvantage\repository\application_registration_repository;
use enrol_lti\local\ltiadvantage\repository\deployment_repository;
use local_lticompletionsync\local\connection;
use local_lticompletionsync\local\receiver\activity_manager;

$gen = new testing_data_generator();
$course = $gen->create_course(['enablecompletion' => 1, 'shortname' => 'CSYNC-UNIN']);
$student = $gen->create_user(['username' => 'unin_student']);
$gen->enrol_user($student->id, $course->id, 'student');
$typeid = lti_add_type((object) ['state' => LTI_TOOL_STATE_CONFIGURED], (object) ['lti_typename' => 'Unin tool',
    'lti_toolurl' => 'https://tool.example.org/enrol/lti/launch.php', 'lti_ltiversion' => LTI_VERSION_1P3,
    'lti_keytype' => LTI_JWK_KEYSET, 'lti_publickeyset' => 'https://tool.example.org/enrol/lti/jwks.php',
    'lti_initiatelogin' => 'https://tool.example.org/enrol/lti/login.php',
    'lti_redirectionuris' => 'https://tool.example.org/enrol/lti/launch.php', 'lti_coursevisible' => 1]);
$cms = [];
foreach (['managed', 'copy'] as $name) {
    $lti = $gen->create_module('lti', ['course' => $course->id, 'typeid' => $typeid, 'toolurl' => '', 'name' => $name,
        'completion' => COMPLETION_TRACKING_MANUAL]);
    $cms[$name] = get_fast_modinfo($course->id)->get_cm($lti->cmid);
}
connection::save((object) ['role' => connection::ROLE_RECEIVER, 'enabled' => 1, 'ltitypeid' => $typeid, 'automanage' => 0,
    'lockdown' => 1, 'conflictpolicy' => connection::POLICY_PROVIDER]);
activity_manager::set_managed($cms['managed'], true);
// A duplicated managed activity whose prohibition core copied before the deferred lockdown task ran.
activity_manager::set_managed($cms['copy'], true, true);
assign_capability(
    'moodle/course:togglecompletion',
    CAP_PROHIBIT,
    $CFG->defaultuserroleid,
    context_module::instance($cms['copy']->id)->id,
    true
);

set_config('allowprivatetargets', 1, 'local_lticompletionsync'); // No DNS on the scratch site.
$reg = (new application_registration_repository())->save(application_registration::create(
    'Unin platform',
    'unin' . random_string(8),
    new moodle_url('https://platform.example.org'),
    'client-unin',
    new moodle_url('https://platform.example.org/a'),
    new moodle_url('https://platform.example.org/j'),
    new moodle_url('https://platform.example.org/t')
));
$dep = (new deployment_repository())->save($reg->add_tool_deployment('Unin dep', '7'));
$senderid = connection::save((object) ['role' => connection::ROLE_SENDER, 'enabled' => 1, 'deploymentid' => $dep->get_id(),
    'endpointurl' => 'https://platform.example.org/local/lticompletionsync/endpoint.php']);
$target = ['connection' => connection::get($senderid),
    'resourcelinkid' => 1, 'toolid' => 1, 'userid' => $student->id, 'sourcekind' => 'course', 'sourceid' => $course->id];
$row = \local_lticompletionsync\local\sender\outbox::record_state((object) $target, 1, 'seed');
\local_lticompletionsync\local\sender\outbox::queue_delivery((int) $row->id);
$DB->insert_record('local_lticompletionsync_rcv', (object) ['connectionid' => 1, 'cmid' => $cms['managed']->id,
    'userid' => $student->id, 'status' => 'applied', 'correlationid' => \core\uuid::generate(), 'timecreated' => time(),
    'timemodified' => time()]);
$DB->insert_record('local_lticompletionsync_jti', (object) ['jtihash' => hash('sha256', 'x'), 'connectionid' => 1,
    'expires' => time() + 60]);
set_config('lastsweep', time(), 'local_lticompletionsync');
\cache::make('local_lticompletionsync', 'platformkeys')->set('k', '{}');

$out = ['cmids' => array_map(fn($cm) => (int) $cm->id, $cms), 'student' => (int) $student->id];
foreach (['con', 'act', 'out', 'rcv', 'jti', 'log'] as $t) {
    $out['rows'][$t] = $DB->count_records('local_lticompletionsync_' . $t);
}
$out['prohibits'] = $DB->count_records('role_capabilities', ['capability' => 'moodle/course:togglecompletion',
    'permission' => CAP_PROHIBIT]);
$out['adhoc'] = $DB->count_records_select('task_adhoc', $DB->sql_like('classname', ':c'), ['c' => '%local_lticompletionsync%']);
$out['cantoggle'] = has_capability('moodle/course:togglecompletion', context_module::instance($cms['managed']->id), $student);
echo json_encode($out) . "\n";
