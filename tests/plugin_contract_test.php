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

namespace local_lticompletionsync;

use local_lticompletionsync\local\journal;

/**
 * Plugin contract: install/upgrade, capabilities, events of spec section 7, language parity, tasks.
 *
 * @package    local_lticompletionsync
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_lticompletionsync\local\journal
 * @covers     \local_lticompletionsync\event\sync_event
 */
final class plugin_contract_test extends \advanced_testcase {
    /**
     * The schema of install.xml is installed and the upgrade function runs idempotently.
     */
    public function test_install_and_upgrade(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $dbman = $DB->get_manager();
        foreach (['con', 'act', 'out', 'rcv', 'jti', 'log'] as $suffix) {
            $name = 'local_lticompletionsync_' . $suffix;
            $this->assertLessThanOrEqual(28, strlen($name));
            $this->assertTrue($dbman->table_exists($name), $name);
        }
        $plugin = new \stdClass();
        require($CFG->dirroot . '/local/lticompletionsync/version.php');
        $this->assertEquals($plugin->version, get_config('local_lticompletionsync', 'version'));
        $this->assertSame(2024100700, $plugin->requires);
        $this->assertSame([405, 503], $plugin->supported);
        $this->assertSame('0.2.0', $plugin->release);
        $this->assertSame(2026102701, $plugin->version);
        // The upgrade function runs idempotently on the current schema (details: upgrade_test).
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/lticompletionsync/db/upgrade.php');
        set_config('version', 2026102601, 'local_lticompletionsync');
        ob_start();
        $this->assertTrue(xmldb_local_lticompletionsync_upgrade(2026102601));
        ob_end_clean();
        $this->assertEquals(2026102701, get_config('local_lticompletionsync', 'version'));
    }

    /**
     * Capabilities with risks and the expected default roles.
     */
    public function test_capabilities(): void {
        $caps = [
            'local/lticompletionsync:manageconnections' => [CONTEXT_SYSTEM, RISK_CONFIG],
            'local/lticompletionsync:manageactivity' => [CONTEXT_MODULE, RISK_CONFIG],
            'local/lticompletionsync:viewstatus' => [CONTEXT_COURSE, RISK_PERSONAL],
        ];
        foreach ($caps as $name => [$level, $risk]) {
            $info = get_capability_info($name);
            $this->assertNotEmpty($info, $name);
            $this->assertEquals($level, $info->contextlevel);
            $this->assertSame($risk, $info->riskbitmask & $risk);
        }
    }

    /**
     * Every standard event of spec section 7 exists as Moodle event with a name string.
     */
    public function test_events(): void {
        $this->resetAfterTest();
        $this->assertCount(11, journal::EVENTS);
        $sink = $this->redirectEvents();
        foreach (journal::EVENTS as $name) {
            $class = '\\local_lticompletionsync\\event\\' . journal::CLASSES[$name];
            $this->assertTrue(class_exists($class), $class);
            $this->assertSame($name, $class::journal_name());
            $this->assertNotEmpty($class::get_name());
            journal::record($name, \core\uuid::generate(), 0, 0, 0, 0, 1, 'test');
        }
        $events = $sink->get_events();
        $sink->close();
        $this->assertCount(11, $events);
        foreach ($events as $event) {
            $this->assertStringContainsString('correlation id', $event->get_description());
        }
        $this->expectException(\coding_exception::class);
        journal::record('invented_event', \core\uuid::generate(), 0);
    }

    /**
     * The journal never stores markup and is purged after the retention.
     */
    public function test_journal_sanitising_and_retention(): void {
        global $DB;
        $this->resetAfterTest();
        journal::record('sent', 'not-a-uuid', 0, 0, 0, 0, 0, '<script>x</script>' . str_repeat('a', 400));
        $row = $DB->get_record('local_lticompletionsync_log', []);
        $this->assertSame('00000000-0000-0000-0000-000000000000', $row->correlationid);
        $this->assertStringNotContainsString('<script>', $row->detail);
        $this->assertLessThanOrEqual(255, strlen($row->detail));
        $this->assertSame(0, journal::purge_old());
        $this->assertSame(1, journal::purge_old(time() + 91 * DAYSECS));
    }

    /**
     * English and German strings have the same keys.
     */
    public function test_language_parity(): void {
        global $CFG;
        $read = function (string $lang): array {
            global $CFG;
            $string = [];
            include($CFG->dirroot . '/local/lticompletionsync/lang/' . $lang . '/local_lticompletionsync.php');
            return $string;
        };
        $en = $read('en');
        $de = $read('de');
        $this->assertSame([], array_diff(array_keys($en), array_keys($de)));
        $this->assertSame([], array_diff(array_keys($de), array_keys($en)));
    }

    /**
     * Scheduled tasks are registered.
     */
    public function test_scheduled_tasks(): void {
        foreach (['sweep_task', 'full_reconcile_task', 'cleanup_task'] as $task) {
            $this->assertNotNull(\core\task\manager::get_scheduled_task('\\local_lticompletionsync\\task\\' . $task));
        }
    }
}
