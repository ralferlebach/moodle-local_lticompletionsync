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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/sync_env.php');

/**
 * Upgrade 0.1.0 (2026102601) -> 0.2.0 (2026102701): the 0.1.0 schema is re-created by dropping what 0.2.0 added,
 * 0.1.0 data is seeded, the real upgrade function runs; data survives, the new fields and the index exist, legacy
 * lockdowns keep the 0.1.0 release behaviour, and a second run changes nothing.
 *
 * @package    local_lticompletionsync
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::xmldb_local_lticompletionsync_upgrade
 */
final class upgrade_test extends \advanced_testcase {
    use tests\sync_env;

    /** @var int Last delivered build (0.1.0). */
    private const V010 = 2026102601;

    /**
     * Bring the plugin's schema and version back to 0.1.0.
     */
    private function become_010(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/lticompletionsync/db/upgrade.php');
        $dbman = $DB->get_manager();
        $act = new \xmldb_table('local_lticompletionsync_act');
        foreach (['priorpermission', 'priorrecorded'] as $name) {
            $dbman->drop_field($act, new \xmldb_field($name));
        }
        $dbman->drop_field(new \xmldb_table('local_lticompletionsync_rcv'), new \xmldb_field('subjecthash'));
        $dbman->drop_index(
            new \xmldb_table('local_lticompletionsync_out'),
            new \xmldb_index('source', XMLDB_INDEX_NOTUNIQUE, ['sourcekind', 'sourceid'])
        );
        set_config('version', self::V010, 'local_lticompletionsync');
    }

    /**
     * Run the upgrade as core would.
     *
     * @return bool
     */
    private function upgrade(): bool {
        ob_start();
        try {
            return xmldb_local_lticompletionsync_upgrade(self::V010);
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Data survives, the schema matches install.xml again, the step is idempotent.
     */
    public function test_upgrade_from_010(): void {
        global $DB;
        $this->resetAfterTest();
        // Rows reference live objects: the 2026102701 step removes orphans (orphan_cleanup_test).
        $this->build_env();
        foreach (['act', 'rcv', 'out'] as $suffix) {
            $DB->delete_records('local_lticompletionsync_' . $suffix);
        }
        $this->become_010();
        $dbman = $DB->get_manager();
        $this->assertFalse($dbman->field_exists('local_lticompletionsync_act', 'priorrecorded'));
        $now = time();
        $act = $DB->insert_record('local_lticompletionsync_act', (object) ['cmid' => $this->pcm->id, 'enabled' => 1,
            'lockedroleid' => 7, 'usermodified' => 2, 'timecreated' => $now, 'timemodified' => $now]);
        $rcv = $DB->insert_record('local_lticompletionsync_rcv', (object) ['connectionid' => $this->receiver->id,
            'cmid' => $this->pcm->id, 'userid' => $this->plearner->id, 'remoteversion' => 3, 'remotestate' => 1,
            'remotetime' => $now, 'appliedversion' => 3,
            'status' => 'applied', 'correlationid' => \core\uuid::generate(), 'timecreated' => $now, 'timemodified' => $now]);
        $out = $DB->insert_record('local_lticompletionsync_out', (object) ['connectionid' => $this->sender->id,
            'resourcelinkid' => 2, 'toolid' => $this->tresource->id, 'userid' => $this->plearner->id, 'sourcekind' => 'cm',
            'sourceid' => $this->tpage->cmid, 'desiredstate' => 1, 'version' => 2,
            'statetime' => $now, 'correlationid' => \core\uuid::generate(), 'status' => 'acked', 'timecreated' => $now,
            'timemodified' => $now]);
        $before = [
            'act' => (array) $DB->get_record('local_lticompletionsync_act', ['id' => $act]),
            'rcv' => (array) $DB->get_record('local_lticompletionsync_rcv', ['id' => $rcv]),
            'out' => (array) $DB->get_record('local_lticompletionsync_out', ['id' => $out]),
        ];

        $this->assertTrue($this->upgrade());
        $this->assertEquals(2026102701, get_config('local_lticompletionsync', 'version'));
        $this->assertTrue($dbman->field_exists('local_lticompletionsync_act', 'priorrecorded'));
        $this->assertTrue($dbman->field_exists('local_lticompletionsync_act', 'priorpermission'));
        $this->assertTrue($dbman->field_exists('local_lticompletionsync_rcv', 'subjecthash'));
        $this->assertTrue($dbman->index_exists(
            new \xmldb_table('local_lticompletionsync_out'),
            new \xmldb_index('source', XMLDB_INDEX_NOTUNIQUE, ['sourcekind', 'sourceid'])
        ));
        $actrow = $DB->get_record('local_lticompletionsync_act', ['id' => $act]);
        $this->assertSame(0, (int) $actrow->priorrecorded, 'prior of a 0.1.0 lockdown is unknown');
        $this->assertNull($actrow->priorpermission);
        $this->assertEquals($before['act'], array_intersect_key((array) $actrow, $before['act']));
        $rcvrow = $DB->get_record('local_lticompletionsync_rcv', ['id' => $rcv]);
        $this->assertNull($rcvrow->subjecthash);
        $this->assertEquals($before['rcv'], array_intersect_key((array) $rcvrow, $before['rcv']));
        $this->assertEquals($before['out'], (array) $DB->get_record('local_lticompletionsync_out', ['id' => $out]));

        // The schema is what install.xml defines (fields of the changed tables).
        $schema = $dbman->get_install_xml_schema();
        foreach (['local_lticompletionsync_act', 'local_lticompletionsync_rcv', 'local_lticompletionsync_out'] as $table) {
            $expected = array_map(fn($f) => $f->getName(), $schema->getTable($table)->getFields());
            $this->assertEqualsCanonicalizing($expected, array_keys($DB->get_columns($table, false)), $table);
        }

        // Idempotent.
        set_config('version', self::V010, 'local_lticompletionsync');
        $this->assertTrue($this->upgrade());
        $this->assertEquals($before['out'], (array) $DB->get_record('local_lticompletionsync_out', ['id' => $out]));
    }
}
