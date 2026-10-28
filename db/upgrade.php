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

/**
 * Upgrade steps for local_lticompletionsync.
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the local_lticompletionsync plugin.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool
 */
function xmldb_local_lticompletionsync_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026102700) {
        // Release 0.2.0 (from 0.1.0, 2026102601).
        // 1. The lockdown remembers the explicit override it replaced, so that releasing it restores that override.
        $table = new xmldb_table('local_lticompletionsync_act');
        $field = new xmldb_field('priorrecorded', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'lockedroleid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('priorpermission', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'priorrecorded');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // Lockdowns written by 0.1.0 overwrote an existing override without remembering it: priorrecorded stays 0 for
        // them (unknown prior state), and their release keeps the 0.1.0 behaviour (the prohibition is removed).

        // 2. The receiver state remembers the subject of its version sequence (account merges can join two sequences).
        $table = new xmldb_table('local_lticompletionsync_rcv');
        $field = new xmldb_field('subjecthash', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'correlationid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // 3. Index for the batched provider-state lookups of the sweep and the course status page.
        $table = new xmldb_table('local_lticompletionsync_out');
        $index = new xmldb_index('source', XMLDB_INDEX_NOTUNIQUE, ['sourcekind', 'sourceid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026102700, 'local', 'lticompletionsync');
    }

    if ($oldversion < 2026102701) {
        // 0.2.0 build 2: remove rows left behind by deleted courses (no course_module_deleted event from core), deleted
        // LTI tool types and enrol_lti registrations/deployments (no events at all), deleted users.
        \local_lticompletionsync\local\orphan_cleanup::sweep(0);
        upgrade_plugin_savepoint(true, 2026102701, 'local', 'lticompletionsync');
    }

    return true;
}
