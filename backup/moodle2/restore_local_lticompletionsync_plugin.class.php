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
 * Restore of the per-activity setting "completion managed by the provider" (lockdown re-applied by a task).
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_local_lticompletionsync_plugin extends restore_local_plugin {
    /** @var bool Whether the restored activity is managed. */
    protected $restoredenabled = false;

    /**
     * Module level paths.
     *
     * @return restore_path_element[]
     */
    protected function define_module_plugin_structure() {
        return [new restore_path_element('lticompletionsync_activity', $this->get_pathfor('/activity'))];
    }

    /**
     * Remember the restored setting (applied after the activity exists completely).
     *
     * @param array|stdClass $data
     */
    public function process_lticompletionsync_activity($data) {
        $data = (object) $data;
        $this->restoredenabled = !empty($data->enabled);
    }

    /**
     * Apply the setting to the new activity through the same API as the user interface (incl. lockdown).
     */
    public function after_restore_module() {
        if (!$this->restoredenabled) {
            return;
        }
        global $DB;
        $cmid = (int) $this->task->get_moduleid();
        $courseid = (int) $DB->get_field('course_modules', 'course', ['id' => $cmid], MUST_EXIST);
        rebuild_course_cache($courseid, true);
        $cm = get_fast_modinfo($courseid)->get_cm($cmid);
        if ($cm->modname !== 'lti') {
            return;
        }
        try {
            // Deferred lockdown: core's duplicate_module() copies the permission overrides only after the restore.
            \local_lticompletionsync\local\receiver\activity_manager::set_managed($cm, true, true);
        } catch (\moodle_exception $e) {
            // Not manageable in the target (no receiver connection, automatic tracking): keep it unmanaged.
            $this->step->log(
                'local_lticompletionsync: activity ' . $cmid . ' not managed after restore: ' . $e->errorcode,
                backup::LOG_WARNING
            );
        }
    }
}
