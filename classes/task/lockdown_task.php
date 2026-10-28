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

use local_lticompletionsync\local\receiver\activity_manager;

/**
 * Receiver side: write the learner lockdown of a managed activity after a restore or duplication.
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lockdown_task extends \core\task\adhoc_task {
    /**
     * Run.
     */
    public function execute() {
        global $DB;
        $cmid = (int) ($this->get_custom_data()->cmid ?? 0);
        $courseid = $DB->get_field('course_modules', 'course', ['id' => $cmid]);
        if (!$courseid) {
            return;
        }
        $adopt = $this->get_custom_data()->adopt ?? null;
        activity_manager::ensure_lockdown(
            get_fast_modinfo($courseid)->get_cm($cmid),
            null,
            $adopt ? (array) $adopt : null
        );
        mtrace('local_lticompletionsync: lockdown checked for activity ' . $cmid);
    }
}
