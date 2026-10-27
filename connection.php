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
 * Create or edit a connection.
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_lticompletionsync\form\connection_form;
use local_lticompletionsync\local\connection;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_lticompletionsync_connections');
require_capability('local/lticompletionsync:manageconnections', context_system::instance());

$id = optional_param('id', 0, PARAM_INT);
$role = optional_param('role', connection::ROLE_SENDER, PARAM_ALPHA);
$existing = $id ? connection::get($id) : null;
if ($id && !$existing) {
    throw new moodle_exception('error_connection', 'local_lticompletionsync');
}
if ($existing) {
    $role = $existing->role;
}
if (!in_array($role, [connection::ROLE_SENDER, connection::ROLE_RECEIVER], true)) {
    throw new moodle_exception('error_role', 'local_lticompletionsync');
}
$returnurl = new moodle_url('/local/lticompletionsync/index.php');
$PAGE->set_url(new moodle_url('/local/lticompletionsync/connection.php', ['id' => $id, 'role' => $role]));

$form = new connection_form(null, ['role' => $role]);
if ($existing) {
    $form->set_data($existing);
} else if ($role === connection::ROLE_SENDER) {
    $form->set_data(['role' => $role, 'enabled' => 0]);
}
if ($form->is_cancelled()) {
    redirect($returnurl);
}
if ($data = $form->get_data()) {
    $data->id = $id;
    connection::save($data);
    redirect($returnurl, get_string('saved', 'local_lticompletionsync'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string($existing ? 'editconnection' : 'add' . $role, 'local_lticompletionsync'));
if ($role === connection::ROLE_SENDER) {
    echo html_writer::div(get_string('sender_intro', 'local_lticompletionsync'), 'mb-3');
} else {
    echo html_writer::div(get_string('receiver_intro', 'local_lticompletionsync', s((new moodle_url(
        '/local/lticompletionsync/endpoint.php'
    ))->out(false))), 'mb-3');
}
$form->display();
echo $OUTPUT->footer();
