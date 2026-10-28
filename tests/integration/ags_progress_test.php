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

namespace local_lticompletionsync\integration;

use local_lticompletionsync\local\connection;
use local_lticompletionsync\local\sender\ags_progress;
use local_lticompletionsync\local\sender\channel;
use local_lticompletionsync\local\sender\dispatcher;
use local_lticompletionsync\local\sender\outbox;
use local_lticompletionsync\tests\loopback_http_client;
use local_lticompletionsync\tests\sync_env;
use Packback\Lti1p3\Interfaces\ILtiRegistration;
use Packback\Lti1p3\Interfaces\ILtiServiceConnector;
use Packback\Lti1p3\Interfaces\IServiceRequest;
use Packback\Lti1p3\LtiAssignmentsGradesService;
use Packback\Lti1p3\LtiRegistration;
use Psr\Http\Message\ResponseInterface;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/sync_env.php');
require_once(__DIR__ . '/../fixtures/loopback_http_client.php');

/**
 * Optional tool-only level: standard AGS progress on a dedicated line item, never on the graded one, and a clear
 * capability refusal when the platform did not grant the AGS scopes.
 *
 * @package    local_lticompletionsync
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_lticompletionsync\local\sender\ags_progress
 * @covers     \local_lticompletionsync\local\sender\dispatcher
 */
final class ags_progress_test extends \advanced_testcase {
    use sync_env;

    /** @var string Coupled (graded) line item of the resource link. */
    private const GRADED = 'https://platform.example.org/lineitems/45/lineitem';

    /**
     * A recording AGS connector.
     *
     * @return ILtiServiceConnector
     */
    private function connector(): ILtiServiceConnector {
        // phpcs:disable moodle.NamingConventions.ValidFunctionName.LowercaseMethod -- the interface names are camel case.
        return new class implements ILtiServiceConnector {
            /** @var array Requests: method, url, body. */
            public $requests = [];
            /**
             * Token.
             *
             * @param ILtiRegistration $registration
             * @param array $scopes
             * @return string
             */
            public function getAccessToken(ILtiRegistration $registration, array $scopes): string {
                return 'token';
            }
            /**
             * Not used.
             *
             * @param IServiceRequest $request
             * @return ResponseInterface
             */
            public function makeRequest(IServiceRequest $request): ResponseInterface {
                throw new \coding_exception('not used');
            }
            /**
             * Not used.
             *
             * @param ResponseInterface $response
             * @return array|null
             */
            public function getResponseBody(ResponseInterface $response): ?array {
                return null;
            }
            /**
             * Not used.
             *
             * @param ResponseInterface $response
             * @return array|null
             */
            public function getResponseHeaders(ResponseInterface $response): ?array {
                return null;
            }
            /**
             * Record and answer a service request.
             *
             * @param ILtiRegistration $registration
             * @param array $scopes
             * @param IServiceRequest $request
             * @param bool $shouldretry
             * @return array
             */
            public function makeServiceRequest(
                ILtiRegistration $registration,
                array $scopes,
                IServiceRequest $request,
                bool $shouldretry = true
            ): array {
                $payload = $request->getPayload();
                $body = isset($payload['body']) ? json_decode($payload['body'], true) : null;
                $this->requests[] = ['method' => $request->getMethod(), 'url' => $request->getUrl(), 'body' => $body];
                if (substr($request->getUrl(), -10) === '/lineitems') {
                    return ['status' => 201, 'body' => $body + ['id' => 'https://platform.example.org/lineitems/99/lineitem']];
                }
                return ['status' => 200, 'body' => []];
            }
            /**
             * Existing line items: only the graded one.
             *
             * @param ILtiRegistration $registration
             * @param array $scopes
             * @param IServiceRequest $request
             * @param string|null $key
             * @return array
             */
            public function getAll(ILtiRegistration $registration, array $scopes, IServiceRequest $request, ?string $key): array {
                $this->requests[] = ['method' => $request->getMethod(), 'url' => $request->getUrl(), 'body' => null];
                return [['id' => 'https://platform.example.org/lineitems/45/lineitem', 'tag' => 'grade',
                    'scoreMaximum' => 100, 'resourceLinkId' => '1']];
            }
            /**
             * Debugging.
             *
             * @param bool $enable
             * @return ILtiServiceConnector
             */
            public function setDebuggingMode(bool $enable): ILtiServiceConnector {
                return $this;
            }
        };
        // phpcs:enable
    }

    /**
     * Dispatcher whose AGS level uses the recording connector, platform without partner plugin.
     *
     * @param ILtiServiceConnector $connector
     * @return dispatcher
     */
    private function dispatcher(ILtiServiceConnector $connector): dispatcher {
        $ags = new class ($connector) extends ags_progress {
            /** @var ILtiServiceConnector */
            private $connector;
            /**
             * Constructor.
             *
             * @param ILtiServiceConnector $connector
             */
            public function __construct(ILtiServiceConnector $connector) {
                $this->connector = $connector;
            }
            /**
             * Service with the recording connector.
             *
             * @param LtiRegistration $registration
             * @param array $servicedata
             * @return LtiAssignmentsGradesService
             */
            protected function get_ags(LtiRegistration $registration, array $servicedata): LtiAssignmentsGradesService {
                return new LtiAssignmentsGradesService($this->connector, $registration, $servicedata);
            }
        };
        $http = new loopback_http_client();
        $http->forcestatus = 404;
        return new dispatcher(new channel($http), $ags);
    }

    /**
     * Progress goes to a dedicated line item without scoreGiven; the graded line item is never touched.
     */
    public function test_progress_on_dedicated_line_item(): void {
        global $DB;
        $this->resetAfterTest();
        $this->build_env();
        $DB->set_field('local_lticompletionsync_con', 'agsprogress', 1, ['id' => $this->sender->id]);
        $tooluser = $this->launch($this->plearner, $this->tresource, null, [
            'scope' => [ags_progress::SCOPE_LINEITEM, ags_progress::SCOPE_SCORE],
            'lineitems' => 'https://platform.example.org/lineitems',
            'lineitem' => self::GRADED,
        ]);
        $this->tool_complete($tooluser, true);
        $connector = $this->connector();
        $row = $this->outrow($tooluser);
        $this->assertSame(outbox::STATUS_SENT, $this->dispatcher($connector)->deliver((int) $row->id));
        $row = $this->outrow($tooluser);
        $this->assertSame('sent', $row->agsstatus);
        $this->assertSame('tool_only_no_confirmation', $row->lasterror);
        $this->assertNull($row->ackstatus);

        $urls = array_column($connector->requests, 'url');
        foreach ($urls as $url) {
            $this->assertStringNotContainsString('/lineitems/45/', $url, 'The graded line item must never be used');
        }
        $create = $connector->requests[1];
        $this->assertSame('POST', $create['method']);
        $this->assertSame(ags_progress::TAG, $create['body']['tag']);
        $this->assertEquals(1, $create['body']['scoreMaximum']);
        $score = end($connector->requests);
        $this->assertSame('https://platform.example.org/lineitems/99/lineitem/scores', $score['url']);
        $this->assertArrayNotHasKey('scoreGiven', $score['body']);
        $this->assertSame('Completed', $score['body']['activityProgress']);
        $this->assertSame('NotReady', $score['body']['gradingProgress']);
        $this->assertSame((string) $this->plearner->id, $score['body']['userId']);
        $level = \local_lticompletionsync\local\status_report::level(connection::get((int) $this->sender->id));
        $this->assertSame('toolonly', $level['key']);
        $this->assertSame(['links' => 1, 'progress' => 1], $level['ags']);
        // Nothing changes natively on the platform: there is no end-to-end guarantee at this level.
        $this->assertSame(0, $this->platform_state());
    }

    /**
     * Without the AGS scopes the capability check refuses instead of reporting a false success.
     */
    public function test_missing_ags_permission_is_reported(): void {
        global $DB;
        $this->resetAfterTest();
        $this->build_env();
        $DB->set_field('local_lticompletionsync_con', 'agsprogress', 1, ['id' => $this->sender->id]);
        $tooluser = $this->launch($this->plearner, $this->tresource, null, [
            'scope' => ['https://purl.imsglobal.org/spec/lti-ags/scope/result.readonly'],
            'lineitems' => 'https://platform.example.org/lineitems',
        ]);
        $this->tool_complete($tooluser, true);
        $connector = $this->connector();
        $this->assertSame(outbox::STATUS_BLOCKED, $this->dispatcher($connector)->deliver((int) $this->outrow($tooluser)->id));
        $this->assertSame('ags_not_permitted', $this->outrow($tooluser)->lasterror);
        $this->assertSame([], $connector->requests);
        // The platform is judged by the capability set it granted: no promise of a tool-only level.
        $level = \local_lticompletionsync\local\status_report::level(connection::get((int) $this->sender->id));
        $this->assertSame('nopartnernoags', $level['key']);
        $this->assertSame(['links' => 1, 'progress' => 0], $level['ags']);
    }
}
