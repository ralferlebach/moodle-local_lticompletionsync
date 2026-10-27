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

namespace local_lticompletionsync\task;

use local_lticompletionsync\local\connection;
use local_lticompletionsync\local\receiver\processor;

/**
 * Receiver side: re-assert the stored provider state after a local change (provider-authoritative policy).
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reassert_task extends \core\task\adhoc_task {
    /**
     * Run.
     */
    public function execute() {
        global $DB;
        $data = $this->get_custom_data();
        $row = $DB->get_record(processor::TABLE, ['id' => (int) ($data->rowid ?? 0)]);
        if (!$row) {
            return;
        }
        $con = connection::get((int) $row->connectionid);
        $courseid = $DB->get_field('course_modules', 'course', ['id' => $row->cmid]);
        if (!$con || !$con->enabled || !$courseid || $con->conflictpolicy !== connection::POLICY_PROVIDER) {
            return;
        }
        $cm = get_fast_modinfo($courseid)->get_cm((int) $row->cmid);
        $result = (new processor())->apply_row($con, $cm, (int) $row->userid, $row);
        mtrace('local_lticompletionsync: re-asserted row ' . (int) $row->id . ' -> ' . $result['status']);
    }
}
