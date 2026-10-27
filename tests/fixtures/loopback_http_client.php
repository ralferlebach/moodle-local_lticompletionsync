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

namespace local_lticompletionsync\tests;

use local_lticompletionsync\local\keys;
use local_lticompletionsync\local\net\http_client;
use local_lticompletionsync\local\net\http_response;
use local_lticompletionsync\local\protocol;
use local_lticompletionsync\local\receiver\endpoint;

/**
 * In-process transport: the sender's request is handled by the real receiver endpoint of the same site (same-site
 * loopback, CS-G1) without a web server. Failure modes can be injected.
 *
 * @package    local_lticompletionsync
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class loopback_http_client implements http_client {
    /** @var string[] Request bodies posted (JWS), for replay tests. */
    public $posted = [];
    /** @var int Number of next requests that are processed but whose answer is lost. */
    public $loseanswers = 0;
    /** @var int Number of next requests that fail before reaching the receiver. */
    public $failrequests = 0;
    /** @var int|null HTTP status to answer with instead of calling the endpoint (e.g. 404: no plugin). */
    public $forcestatus = null;
    /** @var int Number of POST requests. */
    public $posts = 0;
    /** @var int Number of GET requests. */
    public $gets = 0;
    /** @var callable|null Callback receiving the body before it is handled (may return a replacement). */
    public $tamper = null;

    /**
     * POST to the endpoint of this site.
     *
     * @param string $url
     * @param string $body
     * @param string $contenttype
     * @return http_response
     */
    public function post(string $url, string $body, string $contenttype): http_response {
        $this->posts++;
        if ($this->failrequests > 0) {
            $this->failrequests--;
            return new http_response(0, '', '', 'transport_7');
        }
        if ($this->forcestatus !== null) {
            return new http_response($this->forcestatus, '', 'text/html');
        }
        if ($url !== protocol::endpoint_url()) {
            return new http_response(404, '', 'text/html');
        }
        if ($this->tamper) {
            $body = call_user_func($this->tamper, $body);
        }
        $this->posted[] = $body;
        $response = (new endpoint())->handle($body);
        if ($this->loseanswers > 0) {
            $this->loseanswers--;
            return new http_response(0, '', '', 'transport_28');
        }
        return $response;
    }

    /**
     * GET: serves the platform key set of this site.
     *
     * @param string $url
     * @return http_response
     */
    public function get(string $url): http_response {
        $this->gets++;
        if (strpos($url, '/mod/lti/certs.php') !== false) {
            return new http_response(200, keys::platform_keyset_json(), 'application/json');
        }
        return new http_response(404, '', 'text/html');
    }
}
