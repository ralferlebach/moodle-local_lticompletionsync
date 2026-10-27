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

use local_lticompletionsync\local\config;

/**
 * Outgoing HTTP transport based on Moodle's curl wrapper (proxy settings and the core security helper apply).
 *
 * SSRF guard: every URL passes {@see url_guard}; the host is resolved once, every answer must be public, and the
 * connection is pinned to the validated addresses (CURLOPT_RESOLVE) so that a DNS change between check and
 * connect (rebinding) cannot redirect the request. Redirects are never followed. TLS verification is never switched
 * off. Private targets and plain http are only possible with the explicit setting allowprivatetargets (test labs).
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class curl_http_client implements http_client {
    /** @var int Maximum response size in bytes. */
    private const MAX_BODY = 65536;

    /** @var dns_resolver */
    private $dns;
    /** @var bool */
    private $allowprivate;

    /**
     * Constructor.
     *
     * @param dns_resolver|null $dns
     * @param bool|null $allowprivate null = site setting
     */
    public function __construct(?dns_resolver $dns = null, ?bool $allowprivate = null) {
        $this->dns = $dns ?? new system_dns_resolver();
        $this->allowprivate = $allowprivate ?? config::allow_private_targets();
    }

    /**
     * Resolve, validate and pin the target of a request.
     *
     * @param string $url URL that passed the guard
     * @return array|null [curl options, validated addresses] or null to refuse
     */
    public function pin(string $url): ?array {
        $parts = parse_url($url);
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        if ($host === '') {
            return null;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return url_guard::is_internal_address($host) ? null : [[], [$host]];
        }
        $addresses = $this->dns->resolve($host);
        if (!$addresses) {
            return null;
        }
        foreach ($addresses as $address) {
            if (url_guard::is_internal_address($address)) {
                return null;
            }
        }
        $port = (int) ($parts['port'] ?? 443);
        $entries = [];
        foreach ($addresses as $address) {
            $entries[] = $host . ':' . $port . ':' . (strpos($address, ':') !== false ? '[' . $address . ']' : $address);
        }
        return [['CURLOPT_RESOLVE' => $entries], $addresses];
    }

    /**
     * Shared request logic.
     *
     * @param string $method GET or POST
     * @param string $url
     * @param string $body
     * @param string $contenttype
     * @return http_response
     */
    private function request(string $method, string $url, string $body, string $contenttype): http_response {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $reason = url_guard::check($url, $this->allowprivate);
        if ($reason !== url_guard::OK) {
            return new http_response(0, '', '', 'url_' . $reason);
        }
        $pin = [[], []];
        $viaproxy = !empty($CFG->proxyhost) && !is_proxybypass($url);
        if (!$this->allowprivate) {
            $pin = $this->pin($url);
            if ($pin === null) {
                return new http_response(0, '', '', 'url_resolved_private');
            }
        }
        $protocols = $this->allowprivate ? (CURLPROTO_HTTPS | CURLPROTO_HTTP) : CURLPROTO_HTTPS;
        $options = $pin[0] + [
            'CURLOPT_FOLLOWLOCATION' => 0,
            'CURLOPT_MAXREDIRS' => 0,
            'CURLOPT_TIMEOUT' => 20,
            'CURLOPT_CONNECTTIMEOUT' => 8,
            'CURLOPT_SSL_VERIFYPEER' => 1,
            'CURLOPT_SSL_VERIFYHOST' => 2,
            'CURLOPT_PROTOCOLS' => $protocols,
            'CURLOPT_REDIR_PROTOCOLS' => $protocols,
            'CURLOPT_MAXFILESIZE' => self::MAX_BODY,
            'CURLOPT_USERAGENT' => 'MoodleLtiCompletionSync/0.1',
        ];
        $curl = new \curl(['ignoresecurity' => $this->allowprivate, 'cache' => false]);
        if ($method === 'POST') {
            $curl->setHeader(['Content-Type: ' . $contenttype, 'Accept: ' . $contenttype . ', application/json']);
            $result = $curl->post($url, $body, $options);
        } else {
            $curl->setHeader(['Accept: application/json']);
            $result = $curl->get($url, [], $options);
        }
        $info = (array) $curl->get_info();
        if ((int) $curl->get_errno() !== 0 || !is_string($result)) {
            return new http_response(0, '', '', 'transport_' . (int) $curl->get_errno());
        }
        $primary = (string) ($info['primary_ip'] ?? '');
        if (!$this->allowprivate && !$viaproxy && $primary !== '' && $pin[1]) {
            $norm = fn(string $ip): string => bin2hex((string) @inet_pton(trim($ip, '[]')));
            if (!in_array($norm($primary), array_map($norm, $pin[1]), true)) {
                return new http_response(0, '', '', 'pin_mismatch');
            }
        }
        $status = (int) ($info['http_code'] ?? 0);
        if ($status >= 300 && $status < 400) {
            return new http_response($status, '', '', 'redirect');
        }
        return new http_response($status, substr($result, 0, self::MAX_BODY), (string) ($info['content_type'] ?? ''));
    }

    /**
     * POST a body.
     *
     * @param string $url
     * @param string $body
     * @param string $contenttype
     * @return http_response
     */
    public function post(string $url, string $body, string $contenttype): http_response {
        return $this->request('POST', $url, $body, $contenttype);
    }

    /**
     * GET a resource.
     *
     * @param string $url
     * @return http_response
     */
    public function get(string $url): http_response {
        return $this->request('GET', $url, '', '');
    }
}
