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
 * DNS resolver of this server: DNS A and AAAA records, falling back to the system resolver (hosts file).
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class system_dns_resolver implements dns_resolver {
    /**
     * Resolve a host name.
     *
     * @param string $host Host name.
     * @return string[]|null
     */
    public function resolve(string $host): ?array {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $out = [];
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $r) {
                if (!empty($r['ip'])) {
                    $out[] = $r['ip'];
                } else if (!empty($r['ipv6'])) {
                    $out[] = $r['ipv6'];
                }
            }
        }
        if (!$out) {
            $list = @gethostbynamel($host);
            if (is_array($list)) {
                $out = $list;
            } else if ($records === false) {
                return null;
            }
        }
        return array_values(array_unique($out));
    }
}
