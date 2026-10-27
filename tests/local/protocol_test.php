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

/**
 * Unit tests of the protocol constants and mappings (spec section 6).
 *
 * @package    local_lticompletionsync
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_lticompletionsync\local\protocol
 * @covers     \local_lticompletionsync\local\config
 */
final class protocol_test extends \advanced_testcase {
    /**
     * AGS activityProgress values.
     *
     * @return array
     */
    public static function progress_provider(): array {
        return [
            ['Initialized', 0], ['Started', 0], ['InProgress', 0], ['Submitted', 0], ['Completed', 1], ['', 0], ['completed', 0],
        ];
    }

    /**
     * Initialized/Started/InProgress/Submitted map to not completed, Completed to completed.
     *
     * @dataProvider progress_provider
     * @param string $progress
     * @param int $expected
     */
    public function test_progress_mapping(string $progress, int $expected): void {
        $this->assertSame($expected, protocol::progress_to_state($progress));
    }

    /**
     * Wire names of the binary state.
     */
    public function test_state_names(): void {
        $this->assertSame('completed', protocol::state_name(1));
        $this->assertSame('incomplete', protocol::state_name(0));
        $this->assertSame(1, protocol::state_value('completed'));
        $this->assertSame(0, protocol::state_value('incomplete'));
        $this->assertNull(protocol::state_value('Completed'));
        $this->assertNull(protocol::state_value(''));
    }

    /**
     * Correlation ids must be UUIDs.
     */
    public function test_correlation_ids(): void {
        $this->assertTrue(protocol::valid_correlation_id(\core\uuid::generate()));
        $this->assertFalse(protocol::valid_correlation_id('x'));
        $this->assertFalse(protocol::valid_correlation_id('<script>'));
    }

    /**
     * The endpoint is on this site.
     */
    public function test_endpoint_url(): void {
        global $CFG;
        $this->assertSame($CFG->wwwroot . '/local/lticompletionsync/endpoint.php', protocol::endpoint_url());
    }

    /**
     * Backoff doubles per attempt and is bounded; settings are bounded.
     */
    public function test_backoff_and_bounds(): void {
        $this->resetAfterTest();
        $this->assertSame(60, config::backoff_delay(1));
        $this->assertSame(120, config::backoff_delay(2));
        $this->assertSame(240, config::backoff_delay(3));
        $this->assertSame(21600, config::backoff_delay(30));
        set_config('maxattempts', 1000, 'local_lticompletionsync');
        $this->assertSame(50, config::max_attempts());
        set_config('maxattempts', 'abc', 'local_lticompletionsync');
        $this->assertSame(8, config::max_attempts());
        set_config('messagettl', 5, 'local_lticompletionsync');
        $this->assertSame(30, config::message_ttl());
        $this->assertFalse(config::allow_private_targets());
    }
}
