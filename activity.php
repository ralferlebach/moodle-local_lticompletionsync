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
 * Receiver side: provider-managed completion of one external tool activity.
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_lticompletionsync\local\completion_adapter;
use local_lticompletionsync\local\receiver\activity_manager;

require(__DIR__ . '/../../config.php');

$cmid = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'lti');
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('local/lticompletionsync:manageactivity', $context);

$url = new moodle_url('/local/lticompletionsync/activity.php', ['id' => $cm->id]);
$PAGE->set_url($url);
$PAGE->set_title(get_string('activitypage', 'local_lticompletionsync'));
$PAGE->set_heading(format_string($course->fullname));

if ($action === 'enable' || $action === 'disable') {
    require_sesskey();
    activity_manager::set_managed($cm, $action === 'enable');
    redirect($url, get_string('saved', 'local_lticompletionsync'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$row = activity_manager::get($cm->id);
$con = activity_manager::connection_for($cm);
$reason = completion_adapter::unsupported_reason($cm);
$managed = activity_manager::is_managed($cm, $con);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('activitypage', 'local_lticompletionsync') . ': ' . format_string($cm->name));

if (!$con) {
    echo $OUTPUT->notification(get_string('error_noreceiver', 'local_lticompletionsync'), 'warning');
} else if (!$con->enabled) {
    echo $OUTPUT->notification(get_string('receiverdisabled', 'local_lticompletionsync'), 'warning');
}
if ($reason !== '') {
    echo $OUTPUT->notification(get_string('unsupported_' . $reason, 'local_lticompletionsync'), 'warning');
}

$facts = new html_table();
$facts->attributes['class'] = 'generaltable';
$facts->data[] = [get_string('managed', 'local_lticompletionsync'), $managed ? get_string('yes') : get_string('no')];
if ($managed) {
    $lock = activity_manager::lockdown_state($cm);
    $facts->data[] = [get_string('lockdown', 'local_lticompletionsync'),
        $lock['applied'] ? get_string('yes') : get_string('no')];
    $facts->data[] = [get_string('lockdown_open', 'local_lticompletionsync'), (int) $lock['users']];
    $permname = function (?int $permission): string {
        $names = [CAP_ALLOW => 'allow', CAP_PREVENT => 'prevent', CAP_PROHIBIT => 'prohibit'];
        return $permission === null ? get_string('none') :
            (isset($names[$permission]) ? get_string($names[$permission], 'core_role') : (string) $permission);
    };
    $facts->data[] = [get_string('lockdown_override', 'local_lticompletionsync'), $permname($lock['override'])];
    if ($lock['applied']) {
        $facts->data[] = [get_string('lockdown_prior', 'local_lticompletionsync'), $permname($lock['prior'])];
    }
}
echo html_writer::table($facts);
echo html_writer::div(get_string('lockdown_sideeffect', 'local_lticompletionsync'), 'alert alert-info');

if ($con && $reason === '') {
    $target = ($row && $row->enabled) ? 'disable' : 'enable';
    echo $OUTPUT->single_button(
        new moodle_url($url, ['action' => $target, 'sesskey' => sesskey()]),
        get_string('activity_' . $target, 'local_lticompletionsync'),
        'post'
    );
}

$rows = $DB->get_records('local_lticompletionsync_rcv', ['cmid' => $cm->id], 'timemodified DESC');
if ($rows) {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable local-lticompletionsync-rcv';
    $table->head = [get_string('user'), get_string('providerstate', 'local_lticompletionsync'),
        get_string('version', 'local_lticompletionsync'), get_string('appliedversion', 'local_lticompletionsync'),
        get_string('status'), get_string('detail', 'local_lticompletionsync'), get_string('time')];
    $userfields = \core_user\fields::for_name()->get_sql('', false, '', '', false)->selects;
    foreach ($rows as $r) {
        $user = $DB->get_record('user', ['id' => $r->userid], 'id, ' . $userfields);
        $table->data[] = [
            $user ? fullname($user) : '-',
            get_string('state_' . ($r->remotestate ? 'completed' : 'incomplete'), 'local_lticompletionsync'),
            (int) $r->remoteversion,
            (int) $r->appliedversion,
            s($r->status),
            s((string) $r->reason),
            userdate($r->timemodified, get_string('strftimedatetimeshort', 'langconfig')),
        ];
    }
    echo html_writer::table($table);
}
echo $OUTPUT->footer();
