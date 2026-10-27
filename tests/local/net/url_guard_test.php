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

namespace local_lticompletionsync\local\net;

/**
 * SSRF guard and pinning of the outgoing transport (automatic negative tests).
 *
 * @package    local_lticompletionsync
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_lticompletionsync\local\net\url_guard
 * @covers     \local_lticompletionsync\local\net\curl_http_client
 * @covers     \local_lticompletionsync\local\net\http_response
 */
final class url_guard_test extends \advanced_testcase {
    /**
     * URLs that must be refused without the test-lab setting.
     *
     * @return array
     */
    public static function refused_provider(): array {
        return [
            'empty' => ['', 'empty'],
            'http' => ['http://platform.example.org/x', 'scheme'],
            'ftp' => ['ftp://platform.example.org/x', 'scheme'],
            'credentials' => ['https://user:pw@platform.example.org/x', 'credentials'],
            'loopback' => ['https://127.0.0.1/x', 'blocked'],
            'private' => ['https://10.1.2.3/x', 'blocked'],
            'linklocal metadata' => ['https://169.254.169.254/latest', 'blocked'],
            'localhost' => ['https://localhost/x', 'blocked'],
            'internal tld' => ['https://moodle.internal/x', 'blocked'],
            'decimal ip' => ['https://2130706433/x', 'blocked'],
            'hex ip' => ['https://0x7f000001/x', 'blocked'],
            'ipv6 loopback' => ['https://[::1]/x', 'blocked'],
            'ipv4 mapped' => ['https://[::ffff:127.0.0.1]/x', 'blocked'],
            'backslash' => ['https://platform.example.org\\@127.0.0.1/', 'malformed'],
            'too long' => ['https://platform.example.org/' . str_repeat('a', 2100), 'toolong'],
        ];
    }

    /**
     * Refused URLs.
     *
     * @dataProvider refused_provider
     * @param string $url
     * @param string $reason
     */
    public function test_refused(string $url, string $reason): void {
        $this->assertSame($reason, url_guard::check($url, false));
    }

    /**
     * The test-lab setting allows private targets and http, but never credentials or garbage.
     */
    public function test_private_mode(): void {
        $this->assertSame(url_guard::OK, url_guard::check('http://127.0.0.1:8080/x', true));
        $this->assertSame('credentials', url_guard::check('https://a:b@127.0.0.1/x', true));
        $this->assertNotSame(url_guard::OK, url_guard::check('file:///etc/passwd', true));
        $this->assertSame('scheme', url_guard::check('gopher://127.0.0.1/x', true));
    }

    /**
     * DNS pinning: a host resolving to any internal address is refused; public answers are pinned.
     */
    public function test_pinning(): void {
        global $CFG;
        $this->resetAfterTest();
        // No DNS in unit tests: the fake resolver decides (core's IP rules would resolve the name).
        $CFG->curlsecurityblockedhosts = '';
        $fake = new class implements dns_resolver {
            /** @var array */
            public $answers = [];
            /**
             * Resolve.
             *
             * @param string $host
             * @return array|null
             */
            public function resolve(string $host): ?array {
                return $this->answers[$host] ?? null;
            }
        };
        $fake->answers = [
            'rebind.example.org' => ['93.184.216.34', '10.0.0.5'],
            'good.example.org' => ['93.184.216.34'],
            'empty.example.org' => [],
        ];
        $client = new curl_http_client($fake, false);
        $this->assertNull($client->pin('https://rebind.example.org/x'));
        $this->assertNull($client->pin('https://empty.example.org/x'));
        $this->assertNull($client->pin('https://unknown.example.org/x'));
        $pin = $client->pin('https://good.example.org:8443/x');
        $this->assertSame(['CURLOPT_RESOLVE' => ['good.example.org:8443:93.184.216.34']], $pin[0]);

        // A refused URL never reaches the network.
        $response = $client->post('https://rebind.example.org/x', 'body', 'application/jwt');
        $this->assertSame('url_resolved_private', $response->error);
        $response = $client->post('https://127.0.0.1/x', 'body', 'application/jwt');
        $this->assertSame('url_blocked', $response->error);
        $this->assertSame(0, $response->status);
    }

    /**
     * Content type normalisation of responses.
     */
    public function test_response_content_type(): void {
        $response = new http_response(200, 'x', 'Application/JWT; charset=utf-8');
        $this->assertSame('application/jwt', $response->contenttype);
    }
}
