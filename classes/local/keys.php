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

namespace local_lticompletionsync\local;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

/**
 * Signing and verification with the LTI 1.3 keys that core already maintains and the partner already trusts.
 *
 * - Tool side (sender): signs with the enrol_lti tool key (config enrol_lti/lti_13_privatekey, lti_13_kid), whose key
 *   set /enrol/lti/jwks.php the platform has registered for the tool type.
 * - Platform side (receiver): verifies with mod_lti's own lti_verify_jwt_signature() against the tool type's public
 *   key or key set, and signs its answer with the mod_lti platform key (jwks_helper), whose key set
 *   /mod/lti/certs.php the tool has registered in enrol_lti (enrol_lti_app_registration.jwksurl).
 *
 * No new key material and no shared secret are introduced.
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class keys {
    /**
     * Sign claims with the enrol_lti tool key.
     *
     * @param array $claims
     * @return string compact JWS
     * @throws \moodle_exception when the site has no tool key
     */
    public static function tool_sign(array $claims): string {
        $private = get_config('enrol_lti', 'lti_13_privatekey');
        $kid = get_config('enrol_lti', 'lti_13_kid');
        if (empty($private) || empty($kid)) {
            throw new \moodle_exception('error_nokey', config::COMPONENT);
        }
        return JWT::encode($claims, $private, 'RS256', $kid);
    }

    /**
     * Sign claims with the mod_lti platform key.
     *
     * @param array $claims
     * @return string compact JWS
     */
    public static function platform_sign(array $claims): string {
        $key = \mod_lti\local\ltiopenid\jwks_helper::get_private_key();
        return JWT::encode($claims, $key['key'], 'RS256', $key['kid']);
    }

    /**
     * Read header and payload of a compact JWS WITHOUT verification (only to route the request to a connection).
     *
     * @param string $jwt
     * @return array|null [header (array), payload (array)] or null when malformed
     */
    public static function peek(string $jwt): ?array {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        $header = json_decode((string) JWT::urlsafeB64Decode($parts[0]), true);
        $payload = json_decode((string) JWT::urlsafeB64Decode($parts[1]), true);
        if (!is_array($header) || !is_array($payload)) {
            return null;
        }
        return [$header, $payload];
    }

    /**
     * Verify a tool's JWS with the trust that mod_lti has for the tool type (public key or key set).
     *
     * @param int $typeid lti_types.id
     * @param string $clientid client id claimed by the token (must be the type's client id)
     * @param string $jwt
     * @return array verified payload
     * @throws \Exception on any verification failure
     */
    public static function verify_tool_token(int $typeid, string $clientid, string $jwt): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        $leeway = JWT::$leeway;
        JWT::$leeway = config::clock_skew();
        try {
            // Throws when the signature, exp, nbf or iat (with leeway) is invalid or the client id does not match.
            lti_verify_jwt_signature($typeid, $clientid, $jwt);
        } finally {
            JWT::$leeway = $leeway;
        }
        $peek = self::peek($jwt);
        if ($peek === null) {
            throw new \moodle_exception('error_badmessage', config::COMPONENT);
        }
        return $peek[1];
    }

    /**
     * Verify a JWS with a JSON key set.
     *
     * @param string $jwt
     * @param string $jwksjson
     * @return array verified payload
     * @throws \Exception on any verification failure
     */
    public static function verify_with_keyset(string $jwt, string $jwksjson): array {
        $jwks = json_decode($jwksjson, true);
        if (!is_array($jwks) || empty($jwks['keys']) || !is_array($jwks['keys'])) {
            throw new \moodle_exception('error_badkeyset', config::COMPONENT);
        }
        $jwks = \mod_lti\local\ltiopenid\jwks_helper::fix_jwks_alg($jwks, $jwt);
        $jwks['keys'] = array_values(array_filter($jwks['keys'], fn($key) => isset($key['alg']) && $key['alg'] === 'RS256'));
        $leeway = JWT::$leeway;
        JWT::$leeway = config::clock_skew();
        try {
            $payload = JWT::decode($jwt, JWK::parseKeySet($jwks));
        } finally {
            JWT::$leeway = $leeway;
        }
        return json_decode(json_encode($payload), true);
    }

    /**
     * Public key set of the platform key of this site (as served by /mod/lti/certs.php).
     *
     * @return string JSON
     */
    public static function platform_keyset_json(): string {
        return json_encode(\mod_lti\local\ltiopenid\jwks_helper::get_jwks());
    }

    /**
     * Public key (PEM) of the tool key of this site, as a platform would configure it for an RSA-key tool type.
     *
     * @return string
     */
    public static function tool_public_key_pem(): string {
        $private = get_config('enrol_lti', 'lti_13_privatekey');
        $res = openssl_pkey_get_private($private);
        $details = openssl_pkey_get_details($res);
        return $details['key'];
    }
}
