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

use local_lticompletionsync\local\journal;
use local_lticompletionsync\local\status_report;
use local_lticompletionsync\tests\loopback_http_client;
use local_lticompletionsync\tests\sync_env;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/sync_env.php');
require_once(__DIR__ . '/../fixtures/loopback_http_client.php');

/**
 * CS-06: own outcome report (success, failed, revoked, retried with causes) without tool_ltidiagnostics, one
 * correlation id across the outgoing and the incoming side, and no tokens, JWTs or keys in any log.
 *
 * @package    local_lticompletionsync
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_lticompletionsync\local\status_report
 * @covers     \local_lticompletionsync\local\journal
 */
final class report_test extends \advanced_testcase {
    use sync_env;

    /**
     * Audit redaction of credential-like content.
     */
    public function test_redaction(): void {
        $jwt = 'eyJhbGciOiJSUzI1NiJ9.eyJpc3MiOiJ4In0.c2lnbmF0dXJlLXNpZ25hdHVyZQ';
        $this->assertSame('x [redacted-jwt] y', journal::redact("x $jwt y"));
        $this->assertSame('Bearer [redacted]', journal::redact('Bearer abc.def-ghi'));
        $this->assertSame('[redacted-key]', journal::redact("-----BEGIN PRIVATE KEY-----\nMIIabc\n-----END PRIVATE KEY-----"));
        $this->assertSame('t=[redacted-token]', journal::redact('t=' . str_repeat('A1b2', 16)));
        // Codes stay readable.
        foreach (
            ['http_503:connection_not_enabled', 'attempts:transport_7', 'rejected:identity_unresolved', 'state=0',
            'transport_28:+64s'] as $code
        ) {
            $this->assertSame($code, journal::redact($code));
        }
    }

    /**
     * A live sequence produces the report with the right causes, the correlation id is the same on both sides, and
     * neither the journal nor the standard log contains a posted JWS, a key or a token.
     */
    public function test_outcome_report_trace_and_no_secrets_in_logs(): void {
        global $DB;
        $this->resetAfterTest();
        // The standard log store writes only outside a transaction (PostgreSQL test sites reset by rollback).
        $this->preventResetByRollback();
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        get_log_manager(true);
        $this->build_env();
        $http = new loopback_http_client();

        $tooluser = $this->launch($this->plearner, $this->tresource);
        $this->tool_complete($tooluser, true);
        $http->failrequests = 2;
        $this->deliver_all($http);
        $DB->set_field('local_lticompletionsync_out', 'nextattempt', 0, []);
        $this->deliver_all($http);
        $this->tool_complete($tooluser, false);
        $this->deliver_all($http);
        // A learner whose subject cannot be resolved: dead letter with its cause.
        $second = $this->getDataGenerator()->create_and_enrol($this->pcourse, 'student');
        $tool2 = $this->launch($second, $this->tresource, 'not-a-user-id');
        $this->tool_complete($tool2, true);
        $this->deliver_all($http);

        $report = status_report::outcomes(HOURSECS);
        $sender = $report[(int) $this->sender->id];
        $receiver = $report[(int) $this->receiver->id];
        $this->assertSame(2, $sender['success']);
        $this->assertSame(1, $sender['revoked']);
        $this->assertSame(['identity_unresolved' => 1], $sender['failed']);
        $this->assertSame(['transport_7' => 1], $sender['retried']);
        $this->assertSame(1, $receiver['success']);
        $this->assertSame(1, $receiver['revoked']);
        $this->assertSame(['identity_unresolved' => 1], $receiver['failed']);
        $html = status_report::outcomes_table($report);
        $this->assertStringContainsString('identity_unresolved: 1', $html);

        // One correlation id across outgoing (sender rows) and incoming (receiver rows) for the delivered version.
        $corr = $this->outrow($tooluser)->correlationid;
        $events = $DB->get_fieldset_select(journal::TABLE, 'eventname', 'correlationid = :c', ['c' => $corr]);
        foreach (['queued', 'sent', 'http_received', 'identity_resolved', 'completion_revoked', 'acknowledged'] as $e) {
            $this->assertContains($e, $events);
        }
        $logged = $DB->get_fieldset_select(
            'logstore_standard_log',
            'other',
            $DB->sql_like('eventname', ':e'),
            ['e' => '%local_lticompletionsync%']
        );
        $this->assertNotEmpty($logged);
        $this->assertStringContainsString($corr, implode("\n", $logged));

        // No secrets anywhere in the logs.
        $haystack = implode("\n", array_merge(
            $logged,
            $DB->get_fieldset_select(journal::TABLE, 'detail', 'detail IS NOT NULL'),
            $DB->get_fieldset_select('local_lticompletionsync_out', 'lasterror', 'lasterror IS NOT NULL')
        ));
        $this->assertNotEmpty($http->posted);
        foreach ($http->posted as $jws) {
            $this->assertStringNotContainsString(explode('.', $jws)[2], $haystack, 'signature of a posted JWS');
            $this->assertStringNotContainsString(explode('.', $jws)[1], $haystack, 'payload of a posted JWS');
        }
        $this->assertDoesNotMatchRegularExpression('/eyJ[A-Za-z0-9_-]{10,}\./', $haystack);
        $this->assertStringNotContainsString('PRIVATE KEY', $haystack);
        $this->assertStringNotContainsString('Bearer ', $haystack);
    }
}
