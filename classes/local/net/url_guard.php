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
 * SSRF guard for every URL that this plugin sends a completion message or a key set request to.
 *
 * Builds on Moodle's \core\files\curl_security_helper (blocked hosts, blocked ports, DNS
 * resolution) and adds the rules of this plugin: https only, no credentials in the URL, a bounded
 * length, and no private or loopback targets unless an administrator explicitly allowed them.
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class url_guard {
    /** @var string Reason: allowed. */
    public const OK = 'ok';

    /**
     * Check a URL.
     *
     * @param string $url The URL to request.
     * @param bool $allowprivate True when private and loopback targets (and plain http) were explicitly allowed.
     * @return string "ok" or a reason code: empty, toolong, malformed, scheme, credentials, host, blocked
     */
    public static function check(string $url, bool $allowprivate = false): string {
        $url = trim($url);
        if ($url === '') {
            return 'empty';
        }
        if (strlen($url) > 2000 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            // Control characters, blanks and backslashes are parsed differently by PHP and by curl.
            return strlen($url) > 2000 ? 'toolong' : 'malformed';
        }
        if (preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*://([^/?\#]*)#', $url, $m) && strpos($m[1], '@') !== false) {
            return 'credentials';
        }
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return 'malformed';
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && !($allowprivate && $scheme === 'http')) {
            return 'scheme';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'credentials';
        }
        if (!preg_match('/^[A-Za-z0-9.\-\[\]:]+\z/', $parts['host'])) {
            return 'host';
        }
        if (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535)) {
            return 'malformed';
        }
        if ($allowprivate) {
            return self::OK;
        }
        // The built-in rules do not depend on the site's configuration, which an administrator may have emptied.
        if (self::is_internal_host($parts['host'])) {
            return 'blocked';
        }
        $helper = new \core\files\curl_security_helper();
        if ($helper->url_is_blocked($url)) {
            return 'blocked';
        }
        return self::OK;
    }

    /**
     * Whether a host name or literal address points into a private, local or reserved network.
     *
     * @param string $host Host as found in the URL (IPv6 literals with brackets).
     * @return bool
     */
    public static function is_internal_host(string $host): bool {
        $host = strtolower(trim($host, "[] \t."));
        if ($host === '') {
            return true;
        }
        if ($host === 'localhost' || preg_match('/\.(localhost|local|localdomain|internal|lan|home|corp)$/', $host)) {
            return true;
        }
        if (strpos($host, ':') !== false) {
            return self::is_internal_address($host);
        }
        if (preg_match('/^[0-9.]+$/', $host) || preg_match('/^0x[0-9a-f.x]*$/', $host)) {
            // Anything that looks like a number: only a clean dotted quad is judged by range; the rest
            // (decimal, octal and hexadecimal spellings) is refused.
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || self::is_internal_address($host);
        }
        return false;
    }

    /**
     * Whether an IP address is private, loopback, link-local, reserved or a tunnel or translation form of one.
     *
     * IPv6 forms that embed an IPv4 address (mapped, compatible, NAT64, 6to4) are judged by the embedded
     * address; Teredo, documentation, discard and site-local ranges are refused as a whole.
     *
     * @param string $ip Address without brackets.
     * @return bool True for unusable or internal addresses, also for strings that are no address.
     */
    public static function is_internal_address(string $ip): bool {
        $ip = strtolower(trim($ip, '[] '));
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true;
        }
        if (strpos($ip, ':') !== false) {
            return self::is_internal_ipv6($ip);
        }
        return self::is_internal_ipv4($ip);
    }

    /**
     * IPv4 classification.
     *
     * @param string $ip Dotted quad.
     * @return bool
     */
    private static function is_internal_ipv4(string $ip): bool {
        $flags = FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
            return true;
        }
        $long = ip2long($ip);
        if ($long === false) {
            return true;
        }
        // Ranges that PHP does not flag: shared address space (carrier grade NAT), benchmarking, protocol
        // assignments, documentation, 6to4 relays and multicast.
        $ranges = ['100.64.0.0/10', '198.18.0.0/15', '192.0.0.0/24', '192.0.2.0/24', '198.51.100.0/24', '203.0.113.0/24',
            '192.88.99.0/24', '224.0.0.0/4'];
        foreach ($ranges as $range) {
            [$net, $bits] = explode('/', $range);
            $mask = -1 << (32 - (int) $bits);
            if (($long & $mask) === (ip2long($net) & $mask)) {
                return true;
            }
        }
        return false;
    }

    /**
     * IPv6 classification.
     *
     * @param string $ip Valid IPv6 address.
     * @return bool
     */
    private static function is_internal_ipv6(string $ip): bool {
        $packed = inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return true;
        }
        $zeros10 = str_repeat("\0", 10);
        $prefix = substr($packed, 0, 12);
        // IPv4-mapped (::ffff:a.b.c.d), IPv4-compatible (::a.b.c.d) and IPv4-translated (::ffff:0:a.b.c.d).
        if ($prefix === $zeros10 . "\xff\xff" || $prefix === str_repeat("\0", 12)) {
            return self::is_internal_ipv4(inet_ntop(substr($packed, 12)));
        }
        if ($prefix === str_repeat("\0", 8) . "\xff\xff\0\0") {
            return self::is_internal_ipv4(inet_ntop(substr($packed, 12)));
        }
        // NAT64 well-known prefix 64:ff9b::/96 and local-use prefix 64:ff9b:1::/48.
        if (substr($packed, 0, 12) === "\x00\x64\xff\x9b" . str_repeat("\0", 8)) {
            return self::is_internal_ipv4(inet_ntop(substr($packed, 12)));
        }
        if (substr($packed, 0, 6) === "\x00\x64\xff\x9b\x00\x01") {
            return true;
        }
        // 6to4 (2002::/16) embeds the IPv4 address in bits 16 to 47.
        if (substr($packed, 0, 2) === "\x20\x02") {
            return self::is_internal_ipv4(inet_ntop(substr($packed, 2, 4)));
        }
        $first = ord($packed[0]);
        $second = ord($packed[1]);
        if (
            ($first & 0xfe) === 0xfc                       // Unique local fc00::/7.
            || ($first === 0xfe && ($second & 0xc0) === 0x80) // Link-local fe80::/10.
            || ($first === 0xfe && ($second & 0xc0) === 0xc0) // Site-local fec0::/10 (deprecated).
            || $first === 0xff                              // Multicast.
        ) {
            return true;
        }
        if (substr($packed, 0, 4) === "\x20\x01\x00\x00") {
            return true; // Teredo 2001::/32.
        }
        if (substr($packed, 0, 4) === "\x20\x01\x0d\xb8") {
            return true; // Documentation 2001:db8::/32.
        }
        if (substr($packed, 0, 8) === "\x01\x00" . str_repeat("\0", 6)) {
            return true; // Discard-only 100::/64.
        }
        $flags = FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        return filter_var($ip, FILTER_VALIDATE_IP, $flags) === false;
    }
}
