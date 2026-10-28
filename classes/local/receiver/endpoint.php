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

namespace local_lticompletionsync\local\receiver;

use local_lticompletionsync\local\config;
use local_lticompletionsync\local\journal;
use local_lticompletionsync\local\keys;
use local_lticompletionsync\local\net\http_response;
use local_lticompletionsync\local\protocol;

/**
 * The authenticated completion endpoint of the platform side (documented LTI extension, not AGS).
 *
 * Authentication: the request is a JWS signed with the tool key that mod_lti already trusts for the LTI 1.3 tool type
 * of an enabled receiver connection (verified by mod_lti's lti_verify_jwt_signature()). Checks: alg RS256, iss = sub
 * = client id of the type, aud = this endpoint, deployment id = the type's deployment id, bounded life time, iat not
 * in the future, unique jti (replay protection with a unique index), protocol version and message type.
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class endpoint {
    /** @var string Replay table. */
    public const JTI_TABLE = 'local_lticompletionsync_jti';

    /** @var processor */
    private $processor;

    /**
     * Constructor.
     *
     * @param processor|null $processor
     */
    public function __construct(?processor $processor = null) {
        $this->processor = $processor ?? new processor();
    }

    /**
     * Unsigned JSON error (authentication or transport level).
     *
     * @param int $status
     * @param string $code
     * @return http_response
     */
    private static function error(int $status, string $code): http_response {
        return new http_response($status, json_encode(['error' => $code]), 'application/json');
    }

    /**
     * Handle one request body.
     *
     * @param string $body raw request body (compact JWS)
     * @return http_response
     */
    public function handle(string $body): http_response {
        global $CFG;
        $body = trim($body);
        if ($body === '' || strlen($body) > protocol::MAX_BODY) {
            return self::error(400, 'bad_request');
        }
        $peek = keys::peek($body);
        if ($peek === null || ($peek[0]['alg'] ?? '') !== 'RS256' || !is_string($peek[1]['iss'] ?? null)) {
            return self::error(400, 'bad_request');
        }
        $clientid = $peek[1]['iss'];
        [$con, $lookuperror] = \local_lticompletionsync\local\connection::receiver_lookup($clientid);
        if (!$con) {
            return self::error(401, $lookuperror);
        }
        if (!$con->enabled) {
            return self::error(403, 'connection_not_enabled');
        }
        try {
            $claims = keys::verify_tool_token((int) $con->ltitypeid, $clientid, $body);
        } catch (\Throwable $e) {
            return self::error(401, 'invalid_signature');
        }
        $now = time();
        $skew = config::clock_skew();
        $aud = $claims['aud'] ?? null;
        $endpointurl = protocol::endpoint_url();
        $audok = is_string($aud) ? $aud === $endpointurl : (is_array($aud) && in_array($endpointurl, $aud, true));
        $iat = $claims['iat'] ?? null;
        $exp = $claims['exp'] ?? null;
        $jti = $claims['jti'] ?? null;
        if (
            !$audok || ($claims['sub'] ?? null) !== $clientid
            || !is_int($iat) || !is_int($exp) || $iat > $now + $skew || $exp < $now - $skew
            || $exp - $iat > config::message_ttl() + $skew
            || !is_string($jti) || strlen($jti) < 16 || strlen($jti) > 128
        ) {
            return self::error(401, 'invalid_claims');
        }
        $msg = $claims[protocol::CLAIM] ?? null;
        if (!is_array($msg) || ($msg['v'] ?? null) !== protocol::VERSION || !is_string($msg['type'] ?? null)) {
            return self::error(400, 'bad_message');
        }
        if (($msg['deployment_id'] ?? null) !== (string) $con->ltitypeid) {
            return self::error(401, 'wrong_deployment');
        }
        if (!$this->remember_jti((int) $con->id, $jti, $exp + $skew)) {
            return self::error(409, 'replay');
        }
        $correlation = is_string($msg['correlation_id'] ?? null) ? $msg['correlation_id'] : '';
        journal::record(
            'http_received',
            $correlation,
            (int) $con->id,
            0,
            0,
            0,
            is_int($msg['version'] ?? null) ? $msg['version'] : 0,
            $msg['type']
        );

        switch ($msg['type']) {
            case protocol::TYPE_HELLO:
                $result = [
                    'status' => 'ok',
                    'plugin' => config::COMPONENT,
                    'release' => (string) (\core_plugin_manager::instance()->get_plugin_info(config::COMPONENT)->release ?? ''),
                    'protocol' => protocol::VERSION,
                    'levels' => ['cooperative'],
                    'native_completion' => 'manual_tracking_only',
                    'lockdown' => (int) $con->lockdown,
                    'conflictpolicy' => $con->conflictpolicy,
                ];
                break;
            case protocol::TYPE_COMPLETION:
                $result = $this->processor->completion($con, $msg);
                break;
            case protocol::TYPE_STATUS:
                $result = $this->processor->status($con, $msg);
                break;
            default:
                return self::error(400, 'bad_message');
        }
        $result['in_reply_to'] = $jti;
        $result['v'] = protocol::VERSION;
        $response = keys::platform_sign([
            'iss' => $CFG->wwwroot,
            'aud' => $clientid,
            'iat' => $now,
            'exp' => $now + config::message_ttl(),
            'jti' => bin2hex(random_bytes(16)),
            protocol::CLAIM => $result,
        ]);
        return new http_response(200, $response, protocol::CONTENT_TYPE);
    }

    /**
     * Store a message id; false when it was seen before (replay).
     *
     * @param int $connectionid
     * @param string $jti
     * @param int $expires
     * @return bool
     */
    private function remember_jti(int $connectionid, string $jti, int $expires): bool {
        global $DB;
        $hash = hash('sha256', $connectionid . '|' . $jti);
        if ($DB->record_exists(self::JTI_TABLE, ['jtihash' => $hash])) {
            return false;
        }
        try {
            $DB->insert_record(self::JTI_TABLE, (object) [
                'jtihash' => $hash,
                'connectionid' => $connectionid,
                'expires' => $expires,
            ]);
        } catch (\dml_write_exception $e) {
            return false;
        }
        return true;
    }

    /**
     * Delete expired replay entries.
     *
     * @param int|null $now
     */
    public static function purge_jti(?int $now = null): void {
        global $DB;
        $DB->delete_records_select(self::JTI_TABLE, 'expires < :now', ['now' => $now ?? time()]);
    }
}
