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

/**
 * Signing and verification with the LTI keys of core.
 *
 * @package    local_lticompletionsync
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_lticompletionsync\local\keys
 */
final class keys_test extends \advanced_testcase {
    /**
     * Platform signatures verify with the platform key set; tampering and foreign key sets fail.
     */
    public function test_platform_sign_and_verify(): void {
        $this->resetAfterTest();
        $claims = ['iss' => 'x', 'aud' => 'y', 'iat' => time(), 'exp' => time() + 60, 'v' => 1];
        $jwt = keys::platform_sign($claims);
        $payload = keys::verify_with_keyset($jwt, keys::platform_keyset_json());
        $this->assertSame('y', $payload['aud']);

        $parts = explode('.', $jwt);
        $parts[1] = \Firebase\JWT\JWT::urlsafeB64Encode(json_encode(['aud' => 'evil'] + $claims));
        try {
            keys::verify_with_keyset(implode('.', $parts), keys::platform_keyset_json());
            $this->fail('Tampered token accepted');
        } catch (\Throwable $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->expectException(\moodle_exception::class);
        keys::verify_with_keyset($jwt, '{"keys":[]}');
    }

    /**
     * The tool key signs and its public key is derivable; peek reads without verifying.
     */
    public function test_tool_sign_and_peek(): void {
        $this->resetAfterTest();
        $jwt = keys::tool_sign(['iss' => 'client', 'iat' => time(), 'exp' => time() + 60]);
        [$header, $payload] = keys::peek($jwt);
        $this->assertSame('RS256', $header['alg']);
        $this->assertSame(get_config('enrol_lti', 'lti_13_kid'), $header['kid']);
        $this->assertSame('client', $payload['iss']);
        $decoded = \Firebase\JWT\JWT::decode($jwt, new \Firebase\JWT\Key(keys::tool_public_key_pem(), 'RS256'));
        $this->assertSame('client', $decoded->iss);
        $this->assertNull(keys::peek('a.b'));
        $this->assertNull(keys::peek('!!.!!.!!'));
    }

    /**
     * Without a tool key nothing is signed.
     */
    public function test_tool_sign_without_key(): void {
        $this->resetAfterTest();
        unset_config('lti_13_privatekey', 'enrol_lti');
        $this->expectException(\moodle_exception::class);
        keys::tool_sign(['iss' => 'x']);
    }
}
