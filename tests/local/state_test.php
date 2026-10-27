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

use local_lticompletionsync\local\receiver\processor;
use local_lticompletionsync\local\receiver\rejection;
use local_lticompletionsync\local\sender\outbox;

/**
 * Unit tests of the state machines and of the connection register validation.
 *
 * @package    local_lticompletionsync
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_lticompletionsync\local\sender\outbox
 * @covers     \local_lticompletionsync\local\receiver\processor
 * @covers     \local_lticompletionsync\local\connection
 */
final class state_test extends \advanced_testcase {
    /**
     * A fake target.
     *
     * @return \stdClass
     */
    private function target(): \stdClass {
        return (object) [
            'connection' => (object) ['id' => 7], 'resourcelinkid' => 3, 'toolid' => 2, 'userid' => 5,
            'sourcekind' => 'course', 'sourceid' => SITEID,
        ];
    }

    /**
     * Versions are monotonic, state times strictly increasing, unchanged states create nothing, a revocation is a
     * new version and a never reported "incomplete" creates nothing.
     */
    public function test_outbox_versions(): void {
        $this->resetAfterTest();
        $target = $this->target();
        $this->assertNull(outbox::record_state($target, 0, 'test'));
        $v1 = outbox::record_state($target, 1, 'test');
        $this->assertEquals(1, $v1->version);
        $this->assertNull(outbox::record_state($target, 1, 'test'));
        $v2 = outbox::record_state($target, 0, 'test');
        $this->assertEquals(2, $v2->version);
        $this->assertGreaterThan((int) $v1->statetime, (int) $v2->statetime);
        $this->assertNotSame($v1->correlationid, $v2->correlationid);
        $v3 = outbox::record_state($target, 1, 'test');
        $this->assertEquals(3, $v3->version);
        $this->assertGreaterThan((int) $v2->statetime, (int) $v3->statetime);
        $this->assertSame(outbox::STATUS_PENDING, $v3->status);
    }

    /**
     * A delivery result for an old version never overwrites a newer version (optimistic concurrency).
     */
    public function test_outbox_superseded_result_is_dropped(): void {
        global $DB;
        $this->resetAfterTest();
        $target = $this->target();
        $v1 = outbox::record_state($target, 1, 'test');
        outbox::record_state($target, 0, 'test');
        $v1->status = outbox::STATUS_ACKED;
        $this->assertFalse(outbox::save_if_current($v1, ['status']));
        $this->assertSame(outbox::STATUS_PENDING, $DB->get_field(outbox::TABLE, 'status', ['id' => $v1->id]));
        $this->assertSame(outbox::STATUS_PENDING, outbox::schedule_retry($v1, 'x'));
    }

    /**
     * Malformed completion messages.
     *
     * @return array
     */
    public static function bad_message_provider(): array {
        $good = ['resource_link_id' => '12', 'user' => '5', 'state' => 'completed', 'version' => 1, 'timestamp' => 1,
            'correlation_id' => '0f8fad5b-d9cb-469f-a165-70867728950e'];
        return [
            'rl not numeric' => [['resource_link_id' => 'x'] + $good],
            'rl int' => [['resource_link_id' => 12] + $good],
            'no user' => [['user' => ''] + $good],
            'state' => [['state' => 'Completed'] + $good],
            'version 0' => [['version' => 0] + $good],
            'version string' => [['version' => '2'] + $good],
            'timestamp' => [['timestamp' => -1] + $good],
            'correlation' => [['correlation_id' => 'x'] + $good],
        ];
    }

    /**
     * Malformed messages are refused.
     *
     * @dataProvider bad_message_provider
     * @param array $msg
     */
    public function test_bad_messages(array $msg): void {
        try {
            processor::normalise($msg);
            $this->fail('accepted');
        } catch (rejection $e) {
            $this->assertSame('bad_message', $e->reason);
        }
    }

    /**
     * A good message is normalised.
     */
    public function test_good_message(): void {
        $msg = processor::normalise(['resource_link_id' => '12', 'user' => 'abc', 'state' => 'incomplete', 'version' => 4,
            'timestamp' => 9, 'correlation_id' => '0f8fad5b-d9cb-469f-a165-70867728950e']);
        $this->assertSame(12, $msg['resource_link_id']);
        $this->assertSame(0, $msg['state']);
    }

    /**
     * Connection validation: SSRF guard on the endpoint and binding of the endpoint host to the issuer host.
     */
    public function test_connection_validation(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        // No DNS in unit tests: the plugin's own guard decides (core's IP rules would resolve the name).
        $CFG->curlsecurityblockedhosts = '';
        $regid = $DB->insert_record('enrol_lti_app_registration', (object) [
            'name' => 'P', 'platformid' => 'https://platform.example.org', 'clientid' => 'c1', 'uniqueid' => 'u1',
            'platformclienthash' => hash('sha256', 'x'), 'platformuniqueidhash' => hash('sha256', 'y'),
            'authenticationrequesturl' => 'https://platform.example.org/a', 'jwksurl' => 'https://platform.example.org/j',
            'accesstokenurl' => 'https://platform.example.org/t', 'status' => 1, 'timecreated' => 0, 'timemodified' => 0,
        ]);
        $depid = $DB->insert_record('enrol_lti_deployment', (object) ['name' => 'D', 'deploymentid' => '1',
            'platformid' => $regid, 'timecreated' => 0, 'timemodified' => 0]);
        $base = (object) ['role' => connection::ROLE_SENDER, 'deploymentid' => $depid];
        $cases = [
            'http://platform.example.org/local/lticompletionsync/endpoint.php' => 'error_url_scheme',
            'https://127.0.0.1/local/lticompletionsync/endpoint.php' => 'error_url_blocked',
            'https://other.example.org/local/lticompletionsync/endpoint.php' => 'error_url_issuerhost',
            'https://u:p@platform.example.org/x' => 'error_url_credentials',
        ];
        foreach ($cases as $url => $error) {
            $errors = connection::validate((object) ((array) $base + ['endpointurl' => $url]));
            $this->assertSame($error, $errors['endpointurl'] ?? '', $url);
        }
        $this->assertSame(['role' => 'error_role'], connection::validate((object) ['role' => 'admin']));
        $errors = connection::validate((object) ['role' => connection::ROLE_RECEIVER, 'ltitypeid' => 999999]);
        $this->assertSame('error_type', $errors['ltitypeid']);
        $this->expectException(\moodle_exception::class);
        connection::save((object) ((array) $base + ['endpointurl' => 'https://127.0.0.1/x']));
    }
}
