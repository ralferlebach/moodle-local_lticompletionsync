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
 * Connection register and reduced status view (administrators).
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_lticompletionsync\local\connection;
use local_lticompletionsync\local\journal;
use local_lticompletionsync\local\sender\channel;
use local_lticompletionsync\local\sender\outbox;
use local_lticompletionsync\local\status_report;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_lticompletionsync_connections');
$context = context_system::instance();
require_capability('local/lticompletionsync:manageconnections', $context);

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$correlationid = optional_param('correlationid', '', PARAM_ALPHANUMEXT);
$pageurl = new moodle_url('/local/lticompletionsync/index.php');

if ($action !== '') {
    require_sesskey();
    $con = connection::get($id);
    if (!$con) {
        throw new moodle_exception('error_connection', 'local_lticompletionsync');
    }
    $message = '';
    $senderonly = ['probe', 'reconcile', 'resync', 'retrynow', 'requeue', 'refreshkeys'];
    if (in_array($action, $senderonly, true) && $con->role !== connection::ROLE_SENDER) {
        throw new moodle_exception('invalidaction', 'error');
    }
    switch ($action) {
        case 'toggle':
            connection::set_enabled((int) $con->id, !$con->enabled);
            $message = get_string('saved', 'local_lticompletionsync');
            break;
        case 'probe':
            if ($con->role === connection::ROLE_SENDER) {
                $status = (new channel())->probe($con);
                $message = get_string('partner_' . $status, 'local_lticompletionsync');
            }
            break;
        case 'reconcile':
            $task = new \local_lticompletionsync\task\reconcile_task();
            $task->set_custom_data(['connectionid' => (int) $con->id]);
            \core\task\manager::queue_adhoc_task($task, true);
            $message = get_string('reconcilequeued', 'local_lticompletionsync');
            break;
        case 'requeue':
            $message = get_string('requeued', 'local_lticompletionsync', outbox::requeue_connection((int) $con->id));
            break;
        case 'resync':
            // Force resync: every target gets a new version with the current provider state, even if unchanged.
            $task = new \local_lticompletionsync\task\reconcile_task();
            $task->set_custom_data(['connectionid' => (int) $con->id, 'force' => 1]);
            \core\task\manager::queue_adhoc_task($task, true);
            $message = get_string('resyncqueued', 'local_lticompletionsync');
            break;
        case 'retrynow':
            $message = get_string('retrynowdone', 'local_lticompletionsync', outbox::retry_now((int) $con->id));
            break;
        case 'refreshkeys':
            // After a key rotation at the platform: forget the cached key set and check the partner again.
            \cache::make('local_lticompletionsync', 'platformkeys')->purge();
            $status = (new channel())->probe($con);
            $message = get_string('keysrefreshed', 'local_lticompletionsync') . ' ' .
                get_string('partner_' . $status, 'local_lticompletionsync');
            break;
        case 'delete':
            if (!optional_param('confirm', 0, PARAM_BOOL)) {
                echo $OUTPUT->header();
                echo $OUTPUT->confirm(
                    get_string('deleteconfirm', 'local_lticompletionsync', connection::label($con)),
                    new moodle_url($pageurl, ['action' => 'delete', 'id' => $con->id, 'confirm' => 1, 'sesskey' => sesskey()]),
                    $pageurl
                );
                echo $OUTPUT->footer();
                die;
            }
            connection::delete((int) $con->id);
            $message = get_string('deleted', 'local_lticompletionsync');
            break;
        default:
            throw new moodle_exception('invalidaction', 'error');
    }
    redirect($pageurl, $message, null, \core\output\notification::NOTIFY_INFO);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('connections', 'local_lticompletionsync'));
echo html_writer::div(get_string('connections_intro', 'local_lticompletionsync'), 'mb-3');

echo html_writer::div(
    $OUTPUT->single_button(
        new moodle_url('/local/lticompletionsync/connection.php', ['role' => connection::ROLE_SENDER]),
        get_string('addsender', 'local_lticompletionsync'),
        'get'
    ) . ' ' .
    $OUTPUT->single_button(
        new moodle_url('/local/lticompletionsync/connection.php', ['role' => connection::ROLE_RECEIVER]),
        get_string('addreceiver', 'local_lticompletionsync'),
        'get'
    ),
    'mb-3'
);

$connections = connection::all();
if (!$connections) {
    echo $OUTPUT->notification(get_string('noconnections', 'local_lticompletionsync'), 'info');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable local-lticompletionsync-connections';
    $table->head = [
        get_string('role', 'local_lticompletionsync'), get_string('connection', 'local_lticompletionsync'),
        get_string('enabled', 'local_lticompletionsync'), get_string('level', 'local_lticompletionsync'),
        get_string('states', 'local_lticompletionsync'), get_string('actions'),
    ];
    foreach ($connections as $con) {
        $level = status_report::level($con);
        $description = get_string('level_' . $level['key'] . '_desc', 'local_lticompletionsync');
        if (isset($level['ags'])) {
            $description .= ' ' . get_string('agscapability', 'local_lticompletionsync', (object) $level['ags']);
        }
        $levelhtml = $OUTPUT->render_from_template('local_lticompletionsync/level', [
            'ok' => $level['ok'],
            'label' => get_string('level_' . $level['key'], 'local_lticompletionsync'),
            'description' => $description,
        ]);
        $counts = [];
        foreach (status_report::counts($con) as $status => $count) {
            $counts[] = s($status) . ': ' . $count;
        }
        $actions = [];
        $actions[] = html_writer::link(
            new moodle_url('/local/lticompletionsync/connection.php', ['id' => $con->id, 'role' => $con->role]),
            get_string('edit')
        );
        $acts = ['toggle' => $con->enabled ? 'disable' : 'enable', 'delete' => 'delete'];
        if ($con->role === connection::ROLE_SENDER) {
            $acts = ['probe' => 'probe', 'reconcile' => 'reconcile', 'resync' => 'resync', 'retrynow' => 'retrynow',
                'requeue' => 'requeue', 'refreshkeys' => 'refreshkeys'] + $acts;
        }
        foreach ($acts as $act => $label) {
            $actions[] = $OUTPUT->single_button(
                new moodle_url($pageurl, ['action' => $act, 'id' => $con->id, 'sesskey' => sesskey()]),
                get_string('action_' . $label, 'local_lticompletionsync'),
                'post'
            );
        }
        $detail = connection::label($con);
        if ($con->role === connection::ROLE_SENDER) {
            $detail .= html_writer::tag('div', s($con->endpointurl), ['class' => 'small text-muted']);
        }
        $table->data[] = [
            get_string('role_' . $con->role, 'local_lticompletionsync'),
            $detail,
            $con->enabled ? get_string('yes') : get_string('no'),
            $levelhtml,
            implode(html_writer::empty_tag('br'), $counts),
            implode(' ', $actions),
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->heading(get_string('environment', 'local_lticompletionsync'), 3);
$env = new html_table();
$env->attributes['class'] = 'generaltable';
foreach (status_report::environment() as $key => $value) {
    $env->data[] = [get_string($key, 'local_lticompletionsync'), s($value)];
}
echo html_writer::table($env);

echo $OUTPUT->heading(get_string('report', 'local_lticompletionsync'), 3);
echo html_writer::div(get_string('report_intro', 'local_lticompletionsync'), 'mb-2');
echo status_report::outcomes_table(status_report::outcomes(DAYSECS));

echo $OUTPUT->heading(get_string('journal', 'local_lticompletionsync'), 3);
if ($correlationid !== '') {
    echo html_writer::div(get_string('filteredby', 'local_lticompletionsync', s($correlationid)) . ' ' .
        html_writer::link($pageurl, get_string('showall', 'local_lticompletionsync')), 'mb-2');
}
echo status_report::journal_table(journal::recent(['correlationid' => $correlationid], 100));

echo $OUTPUT->footer();
