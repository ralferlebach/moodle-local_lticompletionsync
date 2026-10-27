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
 * Contract of the outgoing HTTP transport. Tests replace it (in-process loopback, failures, delays).
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface http_client {
    /**
     * POST a body.
     *
     * @param string $url
     * @param string $body
     * @param string $contenttype
     * @return http_response
     */
    public function post(string $url, string $body, string $contenttype): http_response;

    /**
     * GET a resource (used for the platform key set).
     *
     * @param string $url
     * @return http_response
     */
    public function get(string $url): http_response;
}
