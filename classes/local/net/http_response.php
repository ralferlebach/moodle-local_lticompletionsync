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
 * Result of one outgoing HTTP request.
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class http_response {
    /** @var int HTTP status, 0 when no response was received. */
    public $status = 0;
    /** @var string Response body (bounded). */
    public $body = '';
    /** @var string Content type (lower case, without parameters). */
    public $contenttype = '';
    /** @var string Reason the request was refused or failed before a response ('' when a response came). */
    public $error = '';

    /**
     * Constructor.
     *
     * @param int $status
     * @param string $body
     * @param string $contenttype
     * @param string $error
     */
    public function __construct(int $status = 0, string $body = '', string $contenttype = '', string $error = '') {
        $this->status = $status;
        $this->body = $body;
        $this->contenttype = strtolower(trim(explode(';', $contenttype)[0]));
        $this->error = $error;
    }
}
