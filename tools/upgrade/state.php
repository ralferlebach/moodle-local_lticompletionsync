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
 * Install/upgrade/rollback check: state of the plugin in a (scratch) site's database, independent of the code version.
 *
 * Usage: CSYNC_ROOT=<tree> LTI_DB=.. LTI_DBNAME=.. LTI_BASE=.. php state.php
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing,moodle.Files.MoodleInternal.MoodleInternalGlobalState -- dev CLI tool.
define('CLI_SCRIPT', true);
require(getenv('CSYNC_ROOT') . '/config.php');
$c = 'local_lticompletionsync';
$out = ['version' => get_config($c, 'version'), 'release_in_code' => null, 'tables' => [], 'columns' => [],
    'togglecompletion_prohibits' => $DB->count_records('role_capabilities', ['capability' => 'moodle/course:togglecompletion',
        'permission' => CAP_PROHIBIT]),
    'tasks' => $DB->count_records_select('task_scheduled', $DB->sql_like('classname', ':c'), ['c' => '%' . $c . '%'])];
$plugin = new stdClass();
$file = core_component::get_component_directory($c) . '/version.php';
if (is_file($file)) {
    require($file);
    $out['release_in_code'] = $plugin->release . ' (' . $plugin->version . ')';
}
foreach (['con', 'act', 'out', 'rcv', 'jti', 'log'] as $t) {
    $table = $c . '_' . $t;
    if ($DB->get_manager()->table_exists($table)) {
        $out['tables'][$t] = $DB->count_records($table);
        $out['columns'][$t] = array_keys($DB->get_columns($table, false));
        sort($out['columns'][$t]);
    }
}
$out['rows'] = [
    'act' => array_values(array_map(fn($r) => array_intersect_key((array) $r, array_flip(['cmid', 'enabled', 'lockedroleid',
        'priorrecorded', 'priorpermission'])), $DB->get_records($c . '_act', null, 'id'))),
];
echo json_encode($out) . "\n";
