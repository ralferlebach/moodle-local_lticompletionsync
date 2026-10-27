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
 * Backup of the per-activity setting "completion managed by the provider".
 *
 * Only the setting is backed up. Transfer states, versions and the journal are runtime data of a live connection and
 * are not copied into another course (the provider re-sends its state through the reconcile).
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_local_lticompletionsync_plugin extends backup_local_plugin {
    /**
     * Module level structure.
     *
     * @return backup_plugin_element
     */
    protected function define_module_plugin_structure() {
        $plugin = $this->get_plugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $activity = new backup_nested_element('activity', ['id'], ['enabled']);
        $plugin->add_child($wrapper);
        $wrapper->add_child($activity);
        $activity->set_source_table('local_lticompletionsync_act', ['cmid' => backup::VAR_MODID]);
        return $plugin;
    }
}
