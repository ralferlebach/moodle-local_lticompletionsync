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
 * Course status of the completion transfer (both roles), without tool_ltidiagnostics.
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_lticompletionsync\local\journal;
use local_lticompletionsync\local\sender\outbox;
use local_lticompletionsync\local\status_report;

require(__DIR__ . '/../../config.php');

$courseid = required_param('id', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/lticompletionsync:viewstatus', $context);

$url = new moodle_url('/local/lticompletionsync/status.php', ['id' => $course->id]);
$PAGE->set_url($url);
$PAGE->set_title(get_string('statuspage', 'local_lticompletionsync'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');

$userfields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('statuspage', 'local_lticompletionsync'));

// Sender role: published resources of this course.
$sql = "SELECT o.*, $userfields
          FROM {" . outbox::TABLE . "} o
          JOIN {user} u ON u.id = o.userid
     LEFT JOIN {course_modules} cm ON o.sourcekind = 'cm' AND cm.id = o.sourceid
         WHERE (o.sourcekind = 'course' AND o.sourceid = :c1) OR (o.sourcekind = 'cm' AND cm.course = :c2)
      ORDER BY o.timemodified DESC";
$outrows = $DB->get_records_sql($sql, ['c1' => $course->id, 'c2' => $course->id], 0, 500);
echo $OUTPUT->heading(get_string('outgoing', 'local_lticompletionsync'), 3);
if (!$outrows) {
    echo $OUTPUT->notification(get_string('none'), 'info');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable local-lticompletionsync-out';
    $table->head = [get_string('user'), get_string('source', 'local_lticompletionsync'),
        get_string('providerstate', 'local_lticompletionsync'), get_string('version', 'local_lticompletionsync'),
        get_string('status'), get_string('ackstatus', 'local_lticompletionsync'), get_string('detail', 'local_lticompletionsync'),
        get_string('correlationid', 'local_lticompletionsync')];
    foreach ($outrows as $r) {
        $table->data[] = [
            fullname($r),
            s($r->sourcekind) . ' ' . (int) $r->sourceid,
            get_string('state_' . ($r->desiredstate ? 'completed' : 'incomplete'), 'local_lticompletionsync'),
            (int) $r->version,
            s($r->status),
            s((string) $r->ackstatus) . ' (' . (int) $r->ackversion . ')',
            s((string) $r->lasterror),
            s($r->correlationid),
        ];
    }
    echo html_writer::table($table);
}

// Receiver role: managed LTI activities of this course.
$sql = "SELECT r.*, $userfields, cm.id AS cmid2
          FROM {local_lticompletionsync_rcv} r
          JOIN {user} u ON u.id = r.userid
          JOIN {course_modules} cm ON cm.id = r.cmid
         WHERE cm.course = :c
      ORDER BY r.timemodified DESC";
$rcvrows = $DB->get_records_sql($sql, ['c' => $course->id], 0, 500);
echo $OUTPUT->heading(get_string('incoming', 'local_lticompletionsync'), 3);
if (!$rcvrows) {
    echo $OUTPUT->notification(get_string('none'), 'info');
} else {
    $modinfo = get_fast_modinfo($course);
    $table = new html_table();
    $table->attributes['class'] = 'generaltable local-lticompletionsync-rcv';
    $table->head = [get_string('user'), get_string('activity'), get_string('providerstate', 'local_lticompletionsync'),
        get_string('version', 'local_lticompletionsync'), get_string('appliedversion', 'local_lticompletionsync'),
        get_string('status'), get_string('detail', 'local_lticompletionsync')];
    foreach ($rcvrows as $r) {
        $cmname = isset($modinfo->cms[$r->cmid]) ? format_string($modinfo->cms[$r->cmid]->name) : (int) $r->cmid;
        $table->data[] = [
            fullname($r),
            $cmname,
            get_string('state_' . ($r->remotestate ? 'completed' : 'incomplete'), 'local_lticompletionsync'),
            (int) $r->remoteversion,
            (int) $r->appliedversion,
            s($r->status),
            s((string) $r->reason),
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->heading(get_string('journal', 'local_lticompletionsync'), 3);
$admin = has_capability('local/lticompletionsync:manageconnections', context_system::instance());
echo status_report::journal_table(journal::recent(['courseid' => $course->id], 100), $admin);
echo $OUTPUT->footer();
