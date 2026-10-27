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

use local_lticompletionsync\local\config;
use local_lticompletionsync\local\connection;
use local_lticompletionsync\local\keys;
use local_lticompletionsync\local\net\curl_http_client;
use local_lticompletionsync\local\net\http_client;
use local_lticompletionsync\local\protocol;

/**
 * Tool side of the authenticated channel: signs a message with the enrol_lti tool key, posts it to the platform
 * endpoint of the connection (SSRF-guarded transport) and verifies the signed answer with the platform key set
 * registered in enrol_lti.
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class channel {
    /** @var http_client */
    private $http;

    /**
     * Constructor.
     *
     * @param http_client|null $http
     */
    public function __construct(?http_client $http = null) {
        $this->http = $http ?? new curl_http_client();
    }

    /**
     * Exchange one message.
     *
     * @param \stdClass $con sender connection
     * @param array $message claim content (without v and deployment_id)
     * @return array httpstatus (int), result (array|null: verified answer claim), error (string code, '' on success),
     *               servererror (string: the unsigned error code of the endpoint, if any)
     */
    public function exchange(\stdClass $con, array $message): array {
        global $DB;
        $out = ['httpstatus' => 0, 'result' => null, 'error' => '', 'servererror' => ''];
        $reg = $DB->get_record('enrol_lti_app_registration', ['id' => $con->registrationid]);
        $dep = $DB->get_record('enrol_lti_deployment', ['id' => $con->deploymentid]);
        if (!$reg || !$dep || (int) $dep->platformid !== (int) $reg->id) {
            $out['error'] = 'registration_missing';
            return $out;
        }
        $now = time();
        $jti = bin2hex(random_bytes(16));
        $claims = [
            'iss' => $reg->clientid,
            'sub' => $reg->clientid,
            'aud' => $con->endpointurl,
            'iat' => $now,
            'exp' => $now + config::message_ttl(),
            'jti' => $jti,
            protocol::CLAIM => ['v' => protocol::VERSION, 'deployment_id' => $dep->deploymentid] + $message,
        ];
        try {
            $jwt = keys::tool_sign($claims);
        } catch (\Throwable $e) {
            $out['error'] = 'no_tool_key';
            return $out;
        }
        $response = $this->http->post($con->endpointurl, $jwt, protocol::CONTENT_TYPE);
        $out['httpstatus'] = $response->status;
        if ($response->error !== '') {
            $out['error'] = $response->error;
            return $out;
        }
        if ($response->status !== 200) {
            $decoded = json_decode($response->body, true);
            $out['servererror'] = is_array($decoded) && is_string($decoded['error'] ?? null) ?
                clean_param($decoded['error'], PARAM_ALPHANUMEXT) : '';
            $out['error'] = 'http_' . $response->status;
            return $out;
        }
        if ($response->contenttype !== protocol::CONTENT_TYPE) {
            $out['error'] = 'bad_response_type';
            return $out;
        }
        try {
            $answer = $this->verify_answer($reg, $response->body);
        } catch (\Throwable $e) {
            $out['error'] = 'bad_response_signature';
            return $out;
        }
        $result = $answer[protocol::CLAIM] ?? null;
        if (
            !is_array($result) || ($answer['aud'] ?? null) !== $reg->clientid
            || rtrim((string) ($answer['iss'] ?? ''), '/') !== rtrim($reg->platformid, '/')
            || ($result['in_reply_to'] ?? null) !== $jti
        ) {
            $out['error'] = 'bad_response_claims';
            return $out;
        }
        $out['result'] = $result;
        return $out;
    }

    /**
     * Verify the platform's answer with the key set registered for the platform (cached, refreshed once on a miss).
     *
     * @param \stdClass $reg enrol_lti_app_registration
     * @param string $jwt
     * @return array verified claims
     * @throws \Exception
     */
    private function verify_answer(\stdClass $reg, string $jwt): array {
        $cache = \cache::make(config::COMPONENT, 'platformkeys');
        $key = sha1($reg->jwksurl);
        $jwks = $cache->get($key);
        if (is_string($jwks)) {
            try {
                return keys::verify_with_keyset($jwt, $jwks);
            } catch (\Throwable $e) {
                // Key rotation at the platform: fetch the key set again below.
                unset($e);
            }
        }
        $response = $this->http->get($reg->jwksurl);
        if ($response->error !== '' || $response->status !== 200) {
            throw new \moodle_exception('error_badkeyset', config::COMPONENT);
        }
        $payload = keys::verify_with_keyset($jwt, $response->body);
        $cache->set($key, $response->body);
        return $payload;
    }

    /**
     * Capability handshake with the partner; stores the partner status on the connection.
     *
     * @param \stdClass $con
     * @return string partner status
     */
    public function probe(\stdClass $con): string {
        global $DB;
        $answer = $this->exchange($con, ['type' => protocol::TYPE_HELLO, 'correlation_id' => \core\uuid::generate()]);
        $status = connection::PARTNER_ERROR;
        $version = null;
        if ($answer['result'] !== null && ($answer['result']['status'] ?? '') === 'ok') {
            $status = connection::PARTNER_PLUGIN;
            $version = \core_text::substr(clean_param((string) ($answer['result']['release'] ?? ''), PARAM_NOTAGS), 0, 64);
        } else if ($answer['httpstatus'] === 404) {
            $status = connection::PARTNER_NOPLUGIN;
        } else if ($answer['httpstatus'] === 403 && $answer['servererror'] === 'connection_not_enabled') {
            $status = connection::PARTNER_DISABLED;
        }
        $DB->update_record(connection::TABLE, (object) [
            'id' => $con->id,
            'partnerstatus' => $status,
            'partnerversion' => $version,
            'timechecked' => time(),
        ]);
        $con->partnerstatus = $status;
        $con->partnerversion = $version;
        return $status;
    }
}
