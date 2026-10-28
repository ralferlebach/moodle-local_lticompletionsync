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

use local_lticompletionsync\local\connection;
use local_lticompletionsync\local\keys;
use local_lticompletionsync\local\protocol;
use local_lticompletionsync\local\receiver\endpoint;
use local_lticompletionsync\local\sender\channel;
use local_lticompletionsync\local\sender\dispatcher;
use local_lticompletionsync\local\sender\outbox;
use local_lticompletionsync\tests\loopback_http_client;
use local_lticompletionsync\tests\sync_env;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/sync_env.php');
require_once(__DIR__ . '/../fixtures/loopback_http_client.php');

/**
 * CS-03: trust boundaries of the channel - foreign and ambiguous client ids, separate connections and contexts, key
 * rotation on either side (fail closed, administrator repair path).
 *
 * @package    local_lticompletionsync
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_lticompletionsync\local\connection
 * @covers     \local_lticompletionsync\local\receiver\endpoint
 * @covers     \local_lticompletionsync\local\receiver\processor
 * @covers     \local_lticompletionsync\local\sender\channel
 * @covers     \local_lticompletionsync\local\sender\outbox
 */
final class trust_test extends \advanced_testcase {
    use sync_env;

    /**
     * Signed claims of a hello message of the environment's tool.
     *
     * @param array $override
     * @return string JWS
     */
    private function hello(array $override = []): string {
        $now = time();
        return keys::tool_sign($override + [
            'iss' => $this->ptype->clientid, 'sub' => $this->ptype->clientid, 'aud' => protocol::endpoint_url(),
            'iat' => $now, 'exp' => $now + 60, 'jti' => bin2hex(random_bytes(16)),
            protocol::CLAIM => ['v' => 1, 'type' => protocol::TYPE_HELLO, 'deployment_id' => (string) $this->ptype->id,
                'correlation_id' => \core\uuid::generate()],
        ]);
    }

    /**
     * Error code of an endpoint answer.
     *
     * @param string $jws
     * @return array [status, error]
     */
    private function answer(string $jws): array {
        $response = (new endpoint())->handle($jws);
        $body = json_decode($response->body, true);
        return [$response->status, is_array($body) ? ($body['error'] ?? null) : null];
    }

    /**
     * A second LTI 1.3 tool type with its own key, activity and receiver connection.
     *
     * @return array [type record, cm_info, connection, private key PEM]
     */
    private function second_tool(): array {
        global $DB;
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $private);
        $public = openssl_pkey_get_details($res)['key'];
        $typeid = lti_add_type((object) ['state' => LTI_TOOL_STATE_CONFIGURED], (object) [
            'lti_typename' => 'Second tool', 'lti_toolurl' => 'https://tool2.example.org/launch',
            'lti_ltiversion' => LTI_VERSION_1P3, 'lti_keytype' => LTI_RSA_KEY, 'lti_publickey' => $public,
            'lti_initiatelogin' => 'https://tool2.example.org/login', 'lti_redirectionuris' => 'https://tool2.example.org/launch',
            'lti_coursevisible' => LTI_COURSEVISIBLE_PRECONFIGURED, 'lti_launchcontainer' => LTI_LAUNCH_CONTAINER_DEFAULT,
        ]);
        $lti = $this->getDataGenerator()->create_module('lti', ['course' => $this->pcourse->id, 'typeid' => $typeid,
            'toolurl' => '', 'completion' => COMPLETION_TRACKING_MANUAL]);
        $cm = get_fast_modinfo($this->pcourse->id)->get_cm($lti->cmid);
        $conid = connection::save((object) ['role' => connection::ROLE_RECEIVER, 'enabled' => 1, 'ltitypeid' => $typeid,
            'automanage' => 1]);
        return [$DB->get_record('lti_types', ['id' => $typeid]), $cm, connection::get($conid), $private];
    }

    /**
     * Foreign (unknown), case variants, ambiguous (two tools with the same client id - prevented by core) and inactive
     * tools never reach a connection.
     */
    public function test_foreign_ambiguous_and_inactive_client_ids_are_refused(): void {
        global $DB;
        $this->resetAfterTest();
        $this->build_env();
        $this->assertSame([200, null], $this->answer($this->hello()));

        // Foreign: a client id no tool of this site carries.
        $this->assertSame(
            [401, connection::LOOKUP_UNKNOWN],
            $this->answer($this->hello(['iss' => 'foreign-client', 'sub' => 'foreign-client']))
        );

        // Ambiguous: two tools with the same client id. Core prevents it with the unique index of lti_types.clientid
        // (mod/lti/db/install.xml); the lookup would refuse it anyway (more than one match, never guessed).
        $copy = clone $this->ptype;
        unset($copy->id);
        $copy->name = 'Copy of the tool';
        try {
            $DB->insert_record('lti_types', $copy);
            $this->fail('core allowed a duplicate client id');
        } catch (\dml_write_exception $e) {
            $this->assertSame(1, $DB->count_records('lti_types', ['clientid' => $this->ptype->clientid]));
        }
        // A client id that differs only in case is a different (unknown) client: no case-insensitive match.
        $variant = strtoupper($this->ptype->clientid) !== $this->ptype->clientid ? strtoupper($this->ptype->clientid) :
            strtolower($this->ptype->clientid);
        $this->assertSame([401, connection::LOOKUP_UNKNOWN], $this->answer($this->hello(['iss' => $variant, 'sub' => $variant])));
        $status = (new channel(new loopback_http_client()))->probe(connection::get((int) $this->sender->id));
        $this->assertSame(connection::PARTNER_PLUGIN, $status);

        // Inactive: the tool type is pending or rejected.
        $DB->set_field('lti_types', 'state', LTI_TOOL_STATE_PENDING, ['id' => $this->ptype->id]);
        $this->assertSame([401, connection::LOOKUP_INACTIVE], $this->answer($this->hello()));
        $DB->set_field('lti_types', 'state', LTI_TOOL_STATE_CONFIGURED, ['id' => $this->ptype->id]);
        $this->assertSame([200, null], $this->answer($this->hello()));
    }

    /**
     * Separate connections and contexts: tool 1 cannot report for tool 2's activity, and tool 1's key is not accepted
     * for tool 2's client id.
     */
    public function test_connections_and_contexts_are_separate(): void {
        $this->resetAfterTest();
        $this->build_env();
        [$type2, $cm2, , $private2] = $this->second_tool();
        $now = time();
        $msg = ['v' => 1, 'type' => protocol::TYPE_COMPLETION, 'deployment_id' => (string) $this->ptype->id,
            'resource_link_id' => (string) $cm2->instance, 'user' => (string) $this->plearner->id, 'state' => 'completed',
            'version' => 1, 'timestamp' => $now, 'correlation_id' => \core\uuid::generate()];
        $response = (new endpoint())->handle($this->hello([protocol::CLAIM => $msg]));
        $this->assertSame(200, $response->status);
        $claims = keys::verify_with_keyset($response->body, keys::platform_keyset_json());
        $this->assertSame('rejected', $claims[protocol::CLAIM]['status']);
        $this->assertSame('wrong_tool', $claims[protocol::CLAIM]['reason']);
        $this->assertSame(0, \local_lticompletionsync\local\completion_adapter::current_state($cm2, (int) $this->plearner->id));

        // Tool 1's key with tool 2's client id: the signature check uses tool 2's key.
        $this->assertSame([401, 'invalid_signature'], $this->answer($this->hello(['iss' => $type2->clientid,
            'sub' => $type2->clientid])));
        // Tool 2's own key with tool 2's client id works, but only for tool 2's deployment.
        $own = \Firebase\JWT\JWT::encode([
            'iss' => $type2->clientid, 'sub' => $type2->clientid, 'aud' => protocol::endpoint_url(), 'iat' => $now,
            'exp' => $now + 60, 'jti' => bin2hex(random_bytes(16)),
            protocol::CLAIM => ['deployment_id' => (string) $this->ptype->id] + $msg,
        ], $private2, 'RS256', 'k2');
        $this->assertSame([401, 'wrong_deployment'], $this->answer($own));
    }

    /**
     * Tool key rotation without updating the platform: deliveries fail closed (nothing applied, retry, no ack); after
     * the platform administrator updated the tool's key, "Retry now" delivers the same version.
     */
    public function test_tool_key_rotation_fails_closed_and_is_repairable(): void {
        global $DB;
        $this->resetAfterTest();
        $this->build_env();
        $tooluser = $this->launch($this->plearner, $this->tresource);
        $this->tool_complete($tooluser, true);

        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $newkey);
        set_config('lti_13_privatekey', $newkey, 'enrol_lti');
        set_config('lti_13_kid', 'rotated-' . random_string(6), 'enrol_lti');

        $http = new loopback_http_client();
        $this->assertSame([outbox::STATUS_RETRY], $this->deliver_all($http));
        $row = $this->outrow($tooluser);
        $this->assertSame('http_401:invalid_signature', $row->lasterror);
        $this->assertSame(0, $this->platform_state());

        // Repair: the platform administrator stores the tool's new public key; then "Retry now".
        $DB->set_field(
            'lti_types_config',
            'value',
            keys::tool_public_key_pem(),
            ['typeid' => $this->ptype->id, 'name' => 'publickey']
        );
        $this->assertSame(1, outbox::retry_now((int) $this->sender->id));
        $this->assertSame(1, (new dispatcher(new channel($http)))->deliver_due());
        $this->assertSame(outbox::STATUS_ACKED, $this->outrow($tooluser)->status);
        $this->assertSame(1, $this->platform_state());
    }

    /**
     * Platform key rotation: the sender's cached key set misses, it is fetched once again and the answer verifies.
     * When the new key set cannot be fetched the answer is not trusted (no ack, retry) although the platform applied
     * the state; the retry is then answered from the stored state (idempotent) and acknowledged.
     */
    public function test_platform_key_rotation(): void {
        $this->resetAfterTest();
        $this->build_env();
        $http = new loopback_http_client();
        (new channel($http))->probe(connection::get((int) $this->sender->id));
        $gets = $http->gets;

        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $newkey);
        set_config('privatekey', $newkey, 'mod_lti');
        set_config('kid', 'rotated-' . random_string(6), 'mod_lti');

        $tooluser = $this->launch($this->plearner, $this->tresource);
        $this->tool_complete($tooluser, true);
        $broken = new class extends loopback_http_client {
            /**
             * Key set not reachable.
             *
             * @param string $url
             * @return \local_lticompletionsync\local\net\http_response
             */
            public function get(string $url): \local_lticompletionsync\local\net\http_response {
                $this->gets++;
                return new \local_lticompletionsync\local\net\http_response(0, '', '', 'transport_7');
            }
        };
        $this->assertSame([outbox::STATUS_RETRY], $this->deliver_all($broken));
        $this->assertSame('bad_response_signature', $this->outrow($tooluser)->lasterror);
        $this->assertSame(1, $this->platform_state(), 'the platform applied it, the sender does not trust the answer');

        // Administrator repair "Refresh platform keys" (cache purge) and retry: one new key set fetch, ack.
        \cache::make('local_lticompletionsync', 'platformkeys')->purge();
        outbox::retry_now((int) $this->sender->id);
        $this->assertSame(1, (new dispatcher(new channel($http)))->deliver_due());
        $this->assertSame(outbox::STATUS_ACKED, $this->outrow($tooluser)->status);
        $this->assertGreaterThan($gets, $http->gets);
    }
}
