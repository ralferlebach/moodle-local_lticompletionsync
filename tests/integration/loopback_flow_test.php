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

namespace local_lticompletionsync\integration;

use local_lticompletionsync\local\completion_adapter;
use local_lticompletionsync\local\connection;
use local_lticompletionsync\local\journal;
use local_lticompletionsync\local\protocol;
use local_lticompletionsync\local\receiver\endpoint;
use local_lticompletionsync\local\sender\channel;
use local_lticompletionsync\local\sender\outbox;
use local_lticompletionsync\local\sender\reconciler;
use local_lticompletionsync\local\status_report;
use local_lticompletionsync\tests\loopback_http_client;
use local_lticompletionsync\tests\sync_env;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/sync_env.php');
require_once(__DIR__ . '/../fixtures/loopback_http_client.php');

/**
 * End-to-end flows on one site with both roles (CS-G1 loopback) through the real core flows: auth_lti account
 * creation, enrol_lti launch, completion API and events on the tool side; the real endpoint, mod_lti signature
 * verification and the completion API on the platform side.
 *
 * @package    local_lticompletionsync
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_lticompletionsync\local\sender\dispatcher
 * @covers     \local_lticompletionsync\local\sender\channel
 * @covers     \local_lticompletionsync\local\sender\outbox
 * @covers     \local_lticompletionsync\local\sender\reconciler
 * @covers     \local_lticompletionsync\local\sender\source
 * @covers     \local_lticompletionsync\local\receiver\endpoint
 * @covers     \local_lticompletionsync\local\receiver\processor
 * @covers     \local_lticompletionsync\observer
 */
final class loopback_flow_test extends \advanced_testcase {
    use sync_env;

    /**
     * Snapshot of the gradebook of the platform course.
     *
     * @return array
     */
    private function gradebook(): array {
        global $DB;
        $items = $DB->get_records('grade_items', ['courseid' => $this->pcourse->id], 'id', 'id');
        [$in, $params] = $DB->get_in_or_equal(array_keys($items) ?: [0]);
        return [
            'items' => $DB->get_records(
                'grade_items',
                ['courseid' => $this->pcourse->id],
                'id',
                'id, itemtype, itemmodule, iteminstance, itemnumber, grademax'
            ),
            'grades' => $DB->get_records_select(
                'grade_grades',
                "itemid $in",
                $params,
                'id',
                'id, itemid, userid, rawgrade, finalgrade, timemodified'
            ),
            'history' => $DB->count_records_select('grade_grades_history', "itemid $in", $params),
        ];
    }

    /**
     * Journal event names of a correlation id.
     *
     * @param string $correlationid
     * @return string[]
     */
    private function events_of(string $correlationid): array {
        global $DB;
        return array_values(array_unique($DB->get_fieldset_select(
            'local_lticompletionsync_log',
            'eventname',
            'correlationid = :c',
            ['c' => $correlationid]
        )));
    }

    /**
     * CS-G1, CS-G3, CS-G4: a completion on the tool side arrives as native completion of the same mod_lti activity,
     * without touching an existing grade, with a signed "applied" acknowledgement and the full event trail.
     */
    public function test_completion_arrives_natively_without_grade_effect(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        $this->build_env();
        // An existing platform grade (e.g. from AGS of the tool).
        grade_update(
            'mod/lti',
            $this->pcourse->id,
            'mod',
            'lti',
            $this->pcm->instance,
            0,
            [$this->plearner->id => (object) ['userid' => $this->plearner->id, 'rawgrade' => 77]]
        );
        $before = $this->gradebook();

        $tooluser = $this->launch($this->plearner, $this->tresource);
        $this->assertNotEquals($this->plearner->id, $tooluser);
        $this->tool_complete($tooluser, true);

        $row = $this->outrow($tooluser);
        $this->assertNotEmpty($row);
        $this->assertSame(outbox::STATUS_PENDING, $row->status);
        $this->assertEquals(1, $row->version);
        $this->assertEquals(0, $this->platform_state());

        $http = new loopback_http_client();
        $this->assertSame([outbox::STATUS_ACKED], $this->deliver_all($http));
        $this->assertSame(1, $this->platform_state());
        $cm = get_fast_modinfo($this->pcourse->id)->get_cm($this->pcm->id);
        $this->assertNull(completion_adapter::overridden_by($cm, (int) $this->plearner->id));
        $this->assertEquals($before, $this->gradebook());

        $row = $this->outrow($tooluser);
        $this->assertSame(protocol::ACK_APPLIED, $row->ackstatus);
        $this->assertEquals(1, $row->ackversion);
        $events = $this->events_of($row->correlationid);
        foreach (
            ['source_detected', 'queued', 'sent', 'http_received', 'identity_resolved', 'completion_applied',
            'acknowledged'] as $event
        ) {
            $this->assertContains($event, $events);
        }
        // Lockdown was applied by automatic management: no tracked learner can self-toggle.
        $this->assertSame([], completion_adapter::users_who_can_toggle($cm)['sample']);
        $this->assertSame('cooperative', status_report::level(connection::get((int) $this->sender->id))['key']);
    }

    /**
     * CS-G5: revocation is a new version, applied natively and stable after cron, sweep and full reconcile.
     */
    public function test_revocation_is_stable_after_cron(): void {
        $this->resetAfterTest();
        $this->build_env();
        // No grade exists: none may be created by completion or revocation.
        $before = $this->gradebook();
        $tooluser = $this->launch($this->plearner, $this->tresource);
        $http = new loopback_http_client();
        $this->tool_complete($tooluser, true);
        $this->deliver_all($http);
        $this->assertSame(1, $this->platform_state());

        $this->tool_complete($tooluser, false);
        $row = $this->outrow($tooluser);
        $this->assertEquals(2, $row->version);
        $this->assertSame(0, (int) $row->desiredstate);
        $this->assertSame([outbox::STATUS_ACKED], $this->deliver_all($http));
        $this->assertSame(0, $this->platform_state());

        // Cron, sweep and full reconcile do not bring it back.
        ob_start();
        (new \core\task\completion_regular_task())->execute();
        (new \local_lticompletionsync\task\sweep_task())->execute();
        (new \local_lticompletionsync\task\full_reconcile_task())->execute();
        ob_end_clean();
        $this->deliver_all($http);
        purge_all_caches();
        $this->assertSame(0, $this->platform_state());
        $this->assertEquals(2, $this->outrow($tooluser)->version);
        $this->assertContains('completion_revoked', $this->events_of($this->outrow($tooluser)->correlationid));
        $this->assertEquals($before, $this->gradebook());
    }

    /**
     * CS-G5: a lost answer leads to a retry of the same version that is answered from the stored state (no second
     * application, no second completion event).
     */
    public function test_lost_answer_retry_is_idempotent(): void {
        global $DB;
        $this->resetAfterTest();
        $this->build_env();
        $tooluser = $this->launch($this->plearner, $this->tresource);
        $this->tool_complete($tooluser, true);
        $http = new loopback_http_client();
        $this->assertSame(connection::PARTNER_PLUGIN, (new channel($http))->probe($this->sender));
        $http->loseanswers = 1;
        $sink = $this->redirectEvents();
        $this->assertSame([outbox::STATUS_RETRY], $this->deliver_all($http));
        $this->assertSame(1, $this->platform_state());
        $row = $this->outrow($tooluser);
        $this->assertSame('transport_28', $row->lasterror);
        $this->assertGreaterThan(time(), (int) $row->nextattempt);

        $DB->set_field('local_lticompletionsync_out', 'nextattempt', 0, ['id' => $row->id]);
        $this->assertSame([outbox::STATUS_ACKED], $this->deliver_all($http));
        $platformevents = array_filter($sink->get_events(), function ($e) {
            return $e instanceof \core\event\course_module_completion_updated
                && (int) $e->contextinstanceid === (int) $this->pcm->id;
        });
        $sink->close();
        $this->assertCount(1, $platformevents);
        $this->assertStringContainsString('duplicate', implode(',', $DB->get_fieldset_select(
            'local_lticompletionsync_log',
            'detail',
            'correlationid = :c',
            ['c' => $row->correlationid]
        )));
    }

    /**
     * CS-G5: an older version arriving late never changes a newer state (out-of-order).
     */
    public function test_out_of_order_old_version_is_rejected(): void {
        global $DB;
        $this->resetAfterTest();
        $this->build_env();
        $tooluser = $this->launch($this->plearner, $this->tresource);
        $http = new loopback_http_client();
        $this->tool_complete($tooluser, true);
        $this->deliver_all($http);
        $this->tool_complete($tooluser, false);
        $this->deliver_all($http);
        $this->assertSame(0, $this->platform_state());

        // A delayed duplicate of version 1 ("completed") arrives now.
        $rl = $DB->get_record('enrol_lti_resource_link', ['id' => $this->outrow($tooluser)->resourcelinkid]);
        $answer = (new channel($http))->exchange($this->sender, [
            'type' => protocol::TYPE_COMPLETION, 'resource_link_id' => (string) $rl->resourcelinkid,
            'user' => (string) $this->plearner->id, 'state' => protocol::STATE_COMPLETED, 'version' => 1,
            'timestamp' => time() - 100, 'correlation_id' => \core\uuid::generate(),
        ]);
        $this->assertSame(protocol::ACK_REJECTED, $answer['result']['status']);
        $this->assertSame('stale_version', $answer['result']['reason']);
        $this->assertSame(2, $answer['result']['appliedversion']);
        $this->assertSame(0, $this->platform_state());
    }

    /**
     * Replay protection: the same signed message is accepted once.
     */
    public function test_replay_is_refused(): void {
        $this->resetAfterTest();
        $this->build_env();
        $tooluser = $this->launch($this->plearner, $this->tresource);
        $http = new loopback_http_client();
        $this->tool_complete($tooluser, true);
        $this->deliver_all($http);
        $this->assertNotEmpty($http->posted);
        $again = (new endpoint())->handle(end($http->posted));
        $this->assertSame(409, $again->status);
        $this->assertSame('replay', json_decode($again->body, true)['error']);
    }

    /**
     * Authentication negatives: tampering, foreign key, wrong audience, expired, wrong deployment, unknown client,
     * receiver not enabled.
     */
    public function test_authentication_negatives(): void {
        global $DB;
        $this->resetAfterTest();
        $this->build_env();
        $now = time();
        $base = [
            'iss' => $this->ptype->clientid, 'sub' => $this->ptype->clientid, 'aud' => protocol::endpoint_url(),
            'iat' => $now, 'exp' => $now + 60, 'jti' => bin2hex(random_bytes(16)),
            protocol::CLAIM => ['v' => 1, 'type' => protocol::TYPE_HELLO, 'deployment_id' => (string) $this->ptype->id,
                'correlation_id' => \core\uuid::generate()],
        ];
        $endpoint = new endpoint();
        $sign = fn(array $claims) => \local_lticompletionsync\local\keys::tool_sign($claims);

        // Valid hello works.
        $ok = $endpoint->handle($sign($base));
        $this->assertSame(200, $ok->status);

        // Tampered payload.
        $jws = explode('.', $sign(['jti' => bin2hex(random_bytes(16))] + $base));
        $payload = json_decode(\Firebase\JWT\JWT::urlsafeB64Decode($jws[1]), true);
        $payload[protocol::CLAIM]['type'] = protocol::TYPE_STATUS;
        $jws[1] = \Firebase\JWT\JWT::urlsafeB64Encode(json_encode($payload));
        $this->assertSame(401, $endpoint->handle(implode('.', $jws))->status);

        // Signed with a foreign key.
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $foreign);
        $forged = \Firebase\JWT\JWT::encode(['jti' => bin2hex(random_bytes(16))] + $base, $foreign, 'RS256', 'x');
        $this->assertSame('invalid_signature', json_decode($endpoint->handle($forged)->body, true)['error']);

        // Wrong audience, expired, too long-lived, wrong deployment, short jti.
        $cases = [
            ['aud' => 'https://evil.example.org/endpoint.php'],
            ['iat' => $now - 1000, 'exp' => $now - 900],
            ['exp' => $now + 3600],
            ['jti' => 'short'],
        ];
        foreach ($cases as $case) {
            $response = $endpoint->handle($sign($case + ['jti' => bin2hex(random_bytes(16))] + $base));
            $this->assertSame(401, $response->status, json_encode($case));
        }
        $wrongdep = $base;
        $wrongdep['jti'] = bin2hex(random_bytes(16));
        $wrongdep[protocol::CLAIM]['deployment_id'] = '999999';
        $this->assertSame('wrong_deployment', json_decode($endpoint->handle($sign($wrongdep))->body, true)['error']);

        // Unknown client.
        $unknown = ['iss' => 'nobody', 'sub' => 'nobody', 'jti' => bin2hex(random_bytes(16))] + $base;
        $this->assertSame(401, $endpoint->handle($sign($unknown))->status);

        // Receiver not enabled: 403, the sender declares "partner disabled".
        $DB->set_field('local_lticompletionsync_con', 'enabled', 0, ['id' => $this->receiver->id]);
        $this->assertSame(403, $endpoint->handle($sign(['jti' => bin2hex(random_bytes(16))] + $base))->status);
        $status = (new channel(new loopback_http_client()))->probe(connection::get((int) $this->sender->id));
        $this->assertSame(connection::PARTNER_DISABLED, $status);

        // Malformed bodies.
        $this->assertSame(400, $endpoint->handle('')->status);
        $this->assertSame(400, $endpoint->handle('a.b')->status);
        $this->assertSame(400, $endpoint->handle(str_repeat('a', protocol::MAX_BODY + 1))->status);
    }

    /**
     * No partner plugin: explicit declaration, nothing pretends success.
     */
    public function test_no_partner_plugin_is_declared(): void {
        $this->resetAfterTest();
        $this->build_env();
        $tooluser = $this->launch($this->plearner, $this->tresource);
        $this->tool_complete($tooluser, true);
        $http = new loopback_http_client();
        $http->forcestatus = 404;
        $this->assertSame([outbox::STATUS_BLOCKED], $this->deliver_all($http));
        $row = $this->outrow($tooluser);
        $this->assertSame('no_partner_plugin', $row->lasterror);
        $con = connection::get((int) $this->sender->id);
        $this->assertSame(connection::PARTNER_NOPLUGIN, $con->partnerstatus);
        $this->assertSame('nopartner', status_report::level($con)['key']);
        $this->assertSame(0, $this->platform_state());
    }

    /**
     * Fail closed: a subject that cannot be resolved (unknown pseudonym, e.g. after a rotation) changes nothing.
     */
    public function test_unresolvable_identity_fails_closed(): void {
        $this->resetAfterTest();
        $this->build_env();
        $tooluser = $this->launch($this->plearner, $this->tresource, 'pseudonym-' . random_string(20));
        $this->tool_complete($tooluser, true);
        $this->assertSame([outbox::STATUS_DEAD], $this->deliver_all(new loopback_http_client()));
        $this->assertSame('identity_unresolved', $this->outrow($tooluser)->lasterror);
        $this->assertSame(0, $this->platform_state());
    }

    /**
     * Pseudonyms of ltisource_ltipseudonym are translated through its read-only service when the plugin is present;
     * after a rotation the old pseudonym fails closed. Without the plugin a pseudonymous subject fails closed.
     */
    public function test_pseudonym_integration_and_rotation(): void {
        $this->resetAfterTest();
        $this->build_env();
        $resolver = new \local_lticompletionsync\local\receiver\identity_resolver();
        if (!\local_lticompletionsync\local\receiver\identity_resolver::pseudonym_service_available()) {
            $this->expectException(\local_lticompletionsync\local\receiver\rejection::class);
            $resolver->resolve($this->ptype, (int) $this->pcourse->id, (int) $this->pcm->id, 'abc-pseudonym');
            return;
        }
        // Policy: random persisted pseudonyms per tool deployment.
        \ltisource_ltipseudonym\local\policy_manager::save(0, 0, 0, (object) [
            'pseudotype' => 'random_persisted', 'scopetype' => 'deployment', 'allowoverride' => 1,
        ], 2);
        $tokens = new \ltisource_ltipseudonym\local\token_service();
        $pseudonym = $tokens->get_or_create(
            (int) $this->plearner->id,
            (int) $this->ptype->id,
            'deployment',
            0,
            'random_persisted'
        );
        $this->assertSame(
            (int) $this->plearner->id,
            $resolver->resolve($this->ptype, (int) $this->pcourse->id, (int) $this->pcm->id, $pseudonym)
        );
        $this->assertSame('pseudonym', $resolver->method);
        // The local id is no longer accepted while the policy pseudonymises.
        try {
            $resolver->resolve($this->ptype, (int) $this->pcourse->id, (int) $this->pcm->id, (string) $this->plearner->id);
            $this->fail('A plain id must not resolve under a pseudonymising policy');
        } catch (\local_lticompletionsync\local\receiver\rejection $e) {
            $this->assertSame('identity_unresolved', $e->reason);
        }
        // Rotation: the old pseudonym fails closed.
        \ltisource_ltipseudonym\local\migration_service::rotate((int) $this->ptype->id, 2);
        $this->expectException(\local_lticompletionsync\local\receiver\rejection::class);
        $resolver->resolve($this->ptype, (int) $this->pcourse->id, (int) $this->pcm->id, $pseudonym);
    }

    /**
     * Conflict rule "provider is authoritative": a teacher override is reverted by the re-assert task.
     */
    public function test_teacher_override_is_reasserted_with_provider_policy(): void {
        $this->resetAfterTest();
        $this->build_env();
        $teacher = $this->getDataGenerator()->create_and_enrol($this->pcourse, 'editingteacher');
        $tooluser = $this->launch($this->plearner, $this->tresource);
        $this->tool_complete($tooluser, true);
        $this->deliver_all(new loopback_http_client());
        $this->assertSame(1, $this->platform_state());

        $this->setUser($teacher);
        \core_completion_external::override_activity_completion_status(
            $this->plearner->id,
            $this->pcm->id,
            COMPLETION_INCOMPLETE
        );
        $this->assertSame(0, $this->platform_state());
        $this->setAdminUser();
        ob_start();
        $this->runAdhocTasks('\local_lticompletionsync\task\reassert_task');
        ob_end_clean();
        $this->assertSame(1, $this->platform_state());
    }

    /**
     * Conflict rule "keep local change": the override stays and the provider is told about the conflict.
     */
    public function test_teacher_override_is_kept_with_local_policy(): void {
        $this->resetAfterTest();
        $this->build_env(['conflictpolicy' => connection::POLICY_LOCAL]);
        $teacher = $this->getDataGenerator()->create_and_enrol($this->pcourse, 'editingteacher');
        $tooluser = $this->launch($this->plearner, $this->tresource);
        $http = new loopback_http_client();
        $this->tool_complete($tooluser, true);
        $this->deliver_all($http);

        $this->setUser($teacher);
        \core_completion_external::override_activity_completion_status(
            $this->plearner->id,
            $this->pcm->id,
            COMPLETION_INCOMPLETE
        );
        $this->setAdminUser();
        $this->assertSame(0, $this->platform_state());

        // The provider revokes and completes again: the local override wins and is reported.
        $this->tool_complete($tooluser, false);
        $this->deliver_all($http);
        $this->tool_complete($tooluser, true);
        $this->assertSame([outbox::STATUS_BLOCKED], $this->deliver_all($http));
        $this->assertSame('conflict_local_override', $this->outrow($tooluser)->lasterror);
        $this->assertSame(0, $this->platform_state());
    }

    /**
     * Without automatic management an activity must be opted in; afterwards a requeue delivers.
     */
    public function test_activity_must_be_managed(): void {
        $this->resetAfterTest();
        $this->build_env(['automanage' => false]);
        $tooluser = $this->launch($this->plearner, $this->tresource);
        $this->tool_complete($tooluser, true);
        $http = new loopback_http_client();
        $this->assertSame([outbox::STATUS_DEAD], $this->deliver_all($http));
        $this->assertSame('activity_not_managed', $this->outrow($tooluser)->lasterror);

        \local_lticompletionsync\local\receiver\activity_manager::set_managed($this->pcm, true);
        outbox::requeue((int) $this->outrow($tooluser)->id);
        $this->assertSame([outbox::STATUS_ACKED], $this->deliver_all($http));
        $this->assertSame(1, $this->platform_state());
    }

    /**
     * Automatic completion tracking on the platform is refused explicitly (CS-G4 step 3).
     */
    public function test_automatic_tracking_on_platform_is_refused(): void {
        global $DB;
        $this->resetAfterTest();
        $this->build_env();
        $DB->set_field('course_modules', 'completion', COMPLETION_TRACKING_AUTOMATIC, ['id' => $this->pcm->id]);
        $DB->set_field('course_modules', 'completionview', 1, ['id' => $this->pcm->id]);
        rebuild_course_cache($this->pcourse->id, true);
        $tooluser = $this->launch($this->plearner, $this->tresource);
        $this->tool_complete($tooluser, true);
        $this->assertSame([outbox::STATUS_DEAD], $this->deliver_all(new loopback_http_client()));
        $this->assertSame('unsupported_tracking_automatic', $this->outrow($tooluser)->lasterror);
        $this->assertSame(0, $this->platform_state());
    }

    /**
     * Transient failures are retried with backoff and end in the dead letter state after the maximum attempts.
     */
    public function test_backoff_and_dead_letter(): void {
        global $DB;
        $this->resetAfterTest();
        $this->build_env();
        set_config('maxattempts', 3, 'local_lticompletionsync');
        $tooluser = $this->launch($this->plearner, $this->tresource);
        $this->tool_complete($tooluser, true);
        $http = new loopback_http_client();
        $http->failrequests = 10;
        $delays = [];
        for ($i = 0; $i < 3; $i++) {
            $DB->set_field('local_lticompletionsync_out', 'nextattempt', 0, ['userid' => $tooluser]);
            $before = time();
            $status = $this->deliver_all($http);
            $row = $this->outrow($tooluser);
            $delays[] = (int) $row->nextattempt - $before;
        }
        $this->assertSame([outbox::STATUS_DEAD], $status);
        $this->assertEquals(3, $row->attempts);
        $this->assertGreaterThanOrEqual(60, $delays[0]);
        $this->assertGreaterThanOrEqual(120, $delays[1]);
        $this->assertContains('dead_letter', $this->events_of($row->correlationid));
        $this->assertContains('retry_scheduled', $this->events_of($row->correlationid));
    }

    /**
     * Parallel deliveries of the same row are serialised by a lock.
     */
    public function test_parallel_delivery_is_locked(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->build_env();
        // Database advisory locks are re-entrant within one connection; record locks behave like a second process.
        $CFG->lock_factory = '\\core\\lock\\db_record_lock_factory';
        $tooluser = $this->launch($this->plearner, $this->tresource);
        $this->tool_complete($tooluser, true);
        $row = $this->outrow($tooluser);
        $lock = \core\lock\lock_config::get_lock_factory('local_lticompletionsync')->get_lock('out_' . $row->id, 0);
        $this->assertNotFalse($lock);
        $http = new loopback_http_client();
        $dispatcher = new \local_lticompletionsync\local\sender\dispatcher(new channel($http));
        $skipped = $dispatcher->deliver((int) $row->id);
        $posts = $http->posts;
        $lock->release();
        $this->assertSame('', $skipped);
        $this->assertSame(0, $posts);
        $this->assertSame(outbox::STATUS_ACKED, $dispatcher->deliver((int) $row->id));
    }

    /**
     * Parallel messages for the same target on the receiver are serialised; a busy target answers "received" and the
     * sender retries.
     */
    public function test_receiver_target_is_locked(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->build_env();
        $CFG->lock_factory = '\\core\\lock\\db_record_lock_factory';
        $tooluser = $this->launch($this->plearner, $this->tresource);
        $this->tool_complete($tooluser, true);
        $http = new loopback_http_client();
        $this->assertSame(connection::PARTNER_PLUGIN, (new channel($http))->probe($this->sender));
        $lock = \core\lock\lock_config::get_lock_factory('local_lticompletionsync')
            ->get_lock('rcv_' . $this->receiver->id . '_' . $this->pcm->id . '_' . $this->plearner->id, 0);
        $this->assertNotFalse($lock);
        $status = $this->deliver_all($http);
        $row = $this->outrow($tooluser);
        $lock->release();
        $this->assertSame([outbox::STATUS_RETRY], $status);
        $this->assertSame('ack_received:busy', $row->lasterror);
        $this->assertSame(0, $this->platform_state());
        $DB->set_field('local_lticompletionsync_out', 'nextattempt', 0, ['id' => $row->id]);
        $this->assertSame([outbox::STATUS_ACKED], $this->deliver_all($http));
        $this->assertSame(1, $this->platform_state());
    }

    /**
     * A published course reports course completion; a revoked course completion is found by the sweep.
     */
    public function test_course_resource_reports_course_completion_and_revocation(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/completion/criteria/completion_criteria_activity.php');
        require_once($CFG->dirroot . '/completion/criteria/completion_criteria.php');
        $this->resetAfterTest();
        $this->build_env();
        // Course completion criterion: the page.
        $criterion = new \completion_criteria_activity();
        $config = (object) ['id' => $this->tcourse->id, 'criteria_activity' => [$this->tpage->cmid => 1]];
        $criterion->update_config($config);
        $DB->insert_record('course_completion_aggr_methd', (object) ['course' => $this->tcourse->id,
            'criteriatype' => null, 'method' => COMPLETION_AGGREGATION_ALL]);
        $tooluser = $this->launch($this->plearner, $this->tcourseresource);
        $this->tool_complete($tooluser, true);
        $row = $DB->get_record('local_lticompletionsync_out', ['userid' => $tooluser, 'sourcekind' => 'course']);
        $this->assertNotEmpty($row, 'course_completed must have queued the course resource');
        $http = new loopback_http_client();
        $this->deliver_all($http);
        $this->assertSame(1, $this->platform_state());

        // Revocation without an event: course completion data deleted (e.g. by a course reset).
        $DB->delete_records('course_completions', ['course' => $this->tcourse->id, 'userid' => $tooluser]);
        $this->assertGreaterThanOrEqual(1, reconciler::sweep(0));
        $this->deliver_all($http);
        $this->assertSame(0, $this->platform_state());
    }

    /**
     * Status query answers the stored receiver state.
     */
    public function test_status_query(): void {
        global $DB;
        $this->resetAfterTest();
        $this->build_env();
        $tooluser = $this->launch($this->plearner, $this->tresource);
        $http = new loopback_http_client();
        $this->tool_complete($tooluser, true);
        $this->deliver_all($http);
        $rl = $DB->get_record('enrol_lti_resource_link', ['id' => $this->outrow($tooluser)->resourcelinkid]);
        $answer = (new channel($http))->exchange($this->sender, [
            'type' => protocol::TYPE_STATUS, 'resource_link_id' => (string) $rl->resourcelinkid,
            'user' => (string) $this->plearner->id, 'correlation_id' => \core\uuid::generate(),
        ]);
        $this->assertSame(protocol::ACK_APPLIED, $answer['result']['status']);
        $this->assertSame(1, $answer['result']['appliedversion']);
        $this->assertSame(protocol::STATE_COMPLETED, $answer['result']['state']);
        $this->assertNotEmpty(journal::recent(['courseid' => $this->pcourse->id]));
    }
}
