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

namespace local_lticompletionsync\tests;

use enrol_lti\local\ltiadvantage\entity\application_registration;
use enrol_lti\local\ltiadvantage\repository\application_registration_repository;
use enrol_lti\local\ltiadvantage\repository\context_repository;
use enrol_lti\local\ltiadvantage\repository\deployment_repository;
use enrol_lti\local\ltiadvantage\repository\resource_link_repository;
use enrol_lti\local\ltiadvantage\repository\user_repository;
use enrol_lti\local\ltiadvantage\service\tool_launch_service;
use local_lticompletionsync\local\connection;
use local_lticompletionsync\local\keys;
use local_lticompletionsync\local\protocol;
use Packback\Lti1p3\LtiMessageLaunch;

/**
 * Same-site environment with both roles (CS-G1): this site is the LTI 1.3 platform (mod_lti type + activity) AND the
 * LTI 1.3 tool (enrol_lti registration for its own issuer, published activity and course). Launches go through the
 * real auth_lti account creation and the real enrol_lti tool_launch_service; only the signed launch JWT itself is
 * mocked (as in core's own enrol_lti tests).
 *
 * @package    local_lticompletionsync
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait sync_env {
    /** @var \stdClass Platform side: course with the external tool activity. */
    protected $pcourse;
    /** @var \stdClass Platform side: learner. */
    protected $plearner;
    /** @var \stdClass Platform side: lti_types record. */
    protected $ptype;
    /** @var \cm_info Platform side: external tool activity (manual completion). */
    protected $pcm;
    /** @var \stdClass Tool side: course with the published resources. */
    protected $tcourse;
    /** @var \stdClass Tool side: published page module record. */
    protected $tpage;
    /** @var \stdClass Tool side: enrol_lti_tools record of the published page. */
    protected $tresource;
    /** @var \stdClass Tool side: enrol_lti_tools record of the published course. */
    protected $tcourseresource;
    /** @var \stdClass Tool side: deployment record. */
    protected $tdeployment;
    /** @var \stdClass Sender connection. */
    protected $sender;
    /** @var \stdClass Receiver connection. */
    protected $receiver;

    /**
     * Build the environment.
     *
     * @param array $options lockdown (bool), automanage (bool), conflictpolicy (string), pagecompletion (int)
     */
    protected function build_env(array $options = []): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        require_once($CFG->libdir . '/completionlib.php');
        $options += [
            'lockdown' => true, 'automanage' => true, 'conflictpolicy' => connection::POLICY_PROVIDER,
            'pagecompletion' => COMPLETION_TRACKING_MANUAL,
        ];
        $gen = $this->getDataGenerator();
        set_config('allowprivatetargets', 1, 'local_lticompletionsync');
        $authclass = \core_plugin_manager::resolve_plugininfo_class('auth');
        $authclass::enable_plugin('lti', true);
        $enrolclass = \core_plugin_manager::resolve_plugininfo_class('enrol');
        $enrolclass::enable_plugin('lti', true);

        // Platform side.
        $this->pcourse = $gen->create_course(['enablecompletion' => 1]);
        $this->plearner = $gen->create_and_enrol($this->pcourse, 'student');
        $type = (object) ['state' => LTI_TOOL_STATE_CONFIGURED];
        $config = (object) [
            'lti_typename' => 'Loopback tool', 'lti_toolurl' => $CFG->wwwroot . '/enrol/lti/launch.php',
            'lti_ltiversion' => LTI_VERSION_1P3, 'lti_keytype' => LTI_RSA_KEY, 'lti_publickey' => keys::tool_public_key_pem(),
            'lti_initiatelogin' => $CFG->wwwroot . '/enrol/lti/login.php', 'lti_redirectionuris' => $CFG->wwwroot .
                '/enrol/lti/launch.php', 'lti_coursevisible' => LTI_COURSEVISIBLE_PRECONFIGURED,
            'lti_acceptgrades' => LTI_SETTING_ALWAYS, 'lti_launchcontainer' => LTI_LAUNCH_CONTAINER_DEFAULT,
        ];
        $typeid = lti_add_type($type, $config);
        $this->ptype = $DB->get_record('lti_types', ['id' => $typeid], '*', MUST_EXIST);
        $lti = $gen->create_module('lti', [
            'course' => $this->pcourse->id, 'typeid' => $typeid, 'toolurl' => '', 'grade' => 100,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $this->pcm = get_fast_modinfo($this->pcourse->id)->get_cm($lti->cmid);

        // Tool side: registration for the own issuer, deployment = the type id (as mod_lti sends it).
        $reg = application_registration::create(
            'Loopback platform',
            'loopback' . random_string(8),
            new \moodle_url($CFG->wwwroot),
            $this->ptype->clientid,
            new \moodle_url('/mod/lti/auth.php'),
            new \moodle_url('/mod/lti/certs.php'),
            new \moodle_url('/mod/lti/token.php')
        );
        $reg = (new application_registration_repository())->save($reg);
        $deployment = (new deployment_repository())->save($reg->add_tool_deployment('Loopback', (string) $typeid));
        $this->tdeployment = $DB->get_record('enrol_lti_deployment', ['id' => $deployment->get_id()], '*', MUST_EXIST);

        $this->tcourse = $gen->create_course(['enablecompletion' => 1]);
        $this->tpage = $gen->create_module('page', [
            'course' => $this->tcourse->id, 'completion' => $options['pagecompletion'],
            'completionview' => $options['pagecompletion'] == COMPLETION_TRACKING_AUTOMATIC ? 1 : 0,
        ]);
        $tool = $gen->create_lti_tool((object) [
            'cmid' => $this->tpage->cmid, 'courseid' => $this->tcourse->id, 'ltiversion' => 'LTI-1p3',
            'provisioningmodelearner' => \auth_plugin_lti::PROVISIONING_MODE_AUTO_ONLY,
            'provisioningmodeinstructor' => \auth_plugin_lti::PROVISIONING_MODE_AUTO_ONLY,
        ]);
        $this->tresource = $DB->get_record('enrol_lti_tools', ['id' => $tool->id], '*', MUST_EXIST);
        $tool = $gen->create_lti_tool((object) [
            'courseid' => $this->tcourse->id, 'ltiversion' => 'LTI-1p3',
            'provisioningmodelearner' => \auth_plugin_lti::PROVISIONING_MODE_AUTO_ONLY,
            'provisioningmodeinstructor' => \auth_plugin_lti::PROVISIONING_MODE_AUTO_ONLY,
        ]);
        $this->tcourseresource = $DB->get_record('enrol_lti_tools', ['id' => $tool->id], '*', MUST_EXIST);

        // Connections on both "sites".
        $this->setAdminUser();
        $senderid = connection::save((object) [
            'role' => connection::ROLE_SENDER, 'enabled' => 1, 'deploymentid' => $this->tdeployment->id,
            'endpointurl' => protocol::endpoint_url(),
        ]);
        $receiverid = connection::save((object) [
            'role' => connection::ROLE_RECEIVER, 'enabled' => 1, 'ltitypeid' => $typeid,
            'automanage' => $options['automanage'] ? 1 : 0, 'lockdown' => $options['lockdown'] ? 1 : 0,
            'conflictpolicy' => $options['conflictpolicy'],
        ]);
        $this->sender = connection::get($senderid);
        $this->receiver = connection::get($receiverid);
    }

    /**
     * A platform learner launches a published resource of the tool side (real auth_lti + enrol_lti flow).
     *
     * @param \stdClass $platformuser platform-side user (the sub is the user id, as mod_lti sends it)
     * @param \stdClass $resource enrol_lti_tools record
     * @param string|null $sub override the subject (pseudonym)
     * @param array|null $ags AGS endpoint claim to include (null: none)
     * @return int tool-side user id
     */
    protected function launch(\stdClass $platformuser, \stdClass $resource, ?string $sub = null, ?array $ags = null): int {
        global $CFG;
        $sub = $sub ?? (string) $platformuser->id;
        $data = [
            'iss' => $CFG->wwwroot,
            'aud' => $this->ptype->clientid,
            'sub' => $sub,
            'exp' => time() + 60,
            'nonce' => 'nonce-' . random_string(8),
            'https://purl.imsglobal.org/spec/lti/claim/deployment_id' => (string) $this->ptype->id,
            'https://purl.imsglobal.org/spec/lti/claim/roles' => ['http://purl.imsglobal.org/vocab/lis/v2/membership#Learner'],
            'https://purl.imsglobal.org/spec/lti/claim/resource_link' => ['id' => (string) $this->pcm->instance,
                'title' => 'Platform activity'],
            'https://purl.imsglobal.org/spec/lti/claim/context' => ['id' => (string) $this->pcourse->id, 'label' => 'P',
                'title' => 'Platform course', 'type' => ['http://purl.imsglobal.org/vocab/lis/v2/course#CourseOffering']],
            'https://purl.imsglobal.org/spec/lti/claim/target_link_uri' => $CFG->wwwroot . '/enrol/lti/launch.php',
            'https://purl.imsglobal.org/spec/lti/claim/custom' => ['id' => $resource->uuid],
            'given_name' => 'Given', 'family_name' => 'Family', 'email' => 'learner' . $sub . '@example.org',
        ];
        if ($ags !== null) {
            $data['https://purl.imsglobal.org/spec/lti-ags/claim/endpoint'] = $ags;
        }
        $mock = $this->getMockBuilder(LtiMessageLaunch::class)
            ->onlyMethods(['getLaunchData', 'getLaunchId'])
            ->disableOriginalConstructor()
            ->getMock();
        $mock->method('getLaunchData')->willReturn($data);
        $mock->method('getLaunchId')->willReturn(uniqid('lti1p3_launch_', true));
        $auth = get_auth_plugin('lti');
        $user = $auth->find_or_create_user_from_launch($data);
        $service = new tool_launch_service(
            new deployment_repository(),
            new application_registration_repository(),
            new resource_link_repository(),
            new user_repository(),
            new context_repository()
        );
        [$userid] = $service->user_launches_tool($user, $mock);
        $this->setAdminUser();
        return (int) $userid;
    }

    /**
     * Set the provider-side completion of the published page (as the learner's toggle or a module rule would).
     *
     * @param int $tooluserid
     * @param bool $completed
     */
    protected function tool_complete(int $tooluserid, bool $completed): void {
        $cm = get_fast_modinfo($this->tcourse->id)->get_cm($this->tpage->cmid);
        $completion = new \completion_info($this->tcourse);
        $completion->update_state($cm, $completed ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE, $tooluserid);
    }

    /**
     * Native completion (0/1) of the platform learner in the platform activity.
     *
     * @param int|null $userid
     * @return int
     */
    protected function platform_state(?int $userid = null): int {
        get_fast_modinfo($this->pcourse->id, 0, true);
        $cm = get_fast_modinfo($this->pcourse->id)->get_cm($this->pcm->id);
        return \local_lticompletionsync\local\completion_adapter::current_state($cm, $userid ?? (int) $this->plearner->id);
    }

    /**
     * Run the queued ad hoc deliveries with a given transport.
     *
     * @param \local_lticompletionsync\local\net\http_client $http
     * @return string[] resulting statuses
     */
    protected function deliver_all(\local_lticompletionsync\local\net\http_client $http): array {
        global $DB;
        $dispatcher = new \local_lticompletionsync\local\sender\dispatcher(
            new \local_lticompletionsync\local\sender\channel($http)
        );
        $out = [];
        foreach ($DB->get_records('local_lticompletionsync_out', null, 'id') as $row) {
            $status = $dispatcher->deliver((int) $row->id);
            if ($status !== '') {
                $out[] = $status;
            }
        }
        // Ad hoc tasks queued by the observer are replaced by the direct delivery above.
        $DB->delete_records_select('task_adhoc', $DB->sql_like('classname', ':c'), ['c' => '%local_lticompletionsync%']);
        return $out;
    }

    /**
     * The outbox row of the published page for a tool user.
     *
     * @param int $tooluserid
     * @return \stdClass|false
     */
    protected function outrow(int $tooluserid) {
        global $DB;
        return $DB->get_record('local_lticompletionsync_out', ['userid' => $tooluserid, 'sourcekind' => 'cm']);
    }
}
