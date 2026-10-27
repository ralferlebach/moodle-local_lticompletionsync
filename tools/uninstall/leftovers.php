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
 * Uninstall check, step 3: everything of the plugin still in the database or the dataroot after the uninstall.
 *
 * Usage: CSYNC_ROOT=<tree> ... php leftovers.php <cmid1,cmid2> <studentid> (see run.sh).
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing,moodle.Files.MoodleInternal.MoodleInternalGlobalState -- dev CLI tool.
define('CLI_SCRIPT', true);
require(getenv('CSYNC_ROOT') . '/config.php');
$c = 'local_lticompletionsync';
$cmids = array_map('intval', explode(',', $argv[1]));
$like = fn(string $field, string $value) => [$DB->sql_like($field, ':v'), ['v' => '%' . $value . '%']];
$checks = [];
$dbman = $DB->get_manager();
$tables = [];
foreach (['con', 'act', 'out', 'rcv', 'jti', 'log'] as $t) {
    if ($dbman->table_exists($c . '_' . $t)) {
        $tables[] = $t;
    }
}
$checks['tables'] = $tables;
$checks['config_plugins'] = $DB->count_records('config_plugins', ['plugin' => $c]);
[$w, $p] = $like('name', 'local/lticompletionsync');
$checks['capabilities'] = $DB->count_records_select('capabilities', $w, $p);
[$w, $p] = $like('capability', 'local/lticompletionsync');
$checks['role_capabilities_own'] = $DB->count_records_select('role_capabilities', $w, $p);
$ctxids = [];
foreach ($cmids as $cmid) {
    $ctxids[] = context_module::instance($cmid)->id;
}
[$in, $p] = $DB->get_in_or_equal($ctxids, SQL_PARAMS_NAMED);
$checks['togglecompletion_overrides_in_managed_activities'] = $DB->count_records_select(
    'role_capabilities',
    "capability = :cap AND contextid $in",
    $p + ['cap' => 'moodle/course:togglecompletion']
);
[$w, $p] = $like('classname', $c);
$checks['task_scheduled'] = $DB->count_records_select('task_scheduled', $w, $p);
$checks['task_adhoc'] = $DB->count_records_select('task_adhoc', $w, $p);
$checks['events_observers_registered'] = count(array_filter(
    array_keys(\core\event\manager::get_all_observers()),
    fn($e) => strpos($e, $c) !== false
));
$checks['files'] = $DB->count_records('files', ['component' => $c]);
[$w, $p] = $like('name', $c);
$checks['user_preferences'] = $DB->count_records_select('user_preferences', $w, $p);
$checks['plugin_version'] = get_config($c, 'version') ?: null;
$muc = file_exists($CFG->dataroot . '/muc/config.php') ? file_get_contents($CFG->dataroot . '/muc/config.php') : '';
$checks['muc_definition'] = strpos($muc, $c . '/platformkeys') !== false;
[$w, $p] = $like('component', $c);
$checks['logstore_rows_kept_by_core'] = $DB->get_manager()->table_exists('logstore_standard_log') ?
    $DB->count_records_select('logstore_standard_log', $w, $p) : 0;
$student = core_user::get_user((int) $argv[2]);
$checks['learner_can_toggle_again'] = [];
foreach ($cmids as $cmid) {
    $checks['learner_can_toggle_again'][] = has_capability(
        'moodle/course:togglecompletion',
        context_module::instance($cmid),
        $student
    );
}
echo json_encode($checks) . "\n";
