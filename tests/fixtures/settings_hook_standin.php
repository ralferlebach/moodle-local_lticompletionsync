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

namespace local_lticompletionsync\tests;

/**
 * Stand-in for \tool_mergeusers\hook\add_settings_before_merging (tool_mergeusers is not installed on the test sites).
 *
 * It offers exactly the public API the plugin's callback uses and merges like
 * \tool_mergeusers\local\db_config::add_raw() (array_replace_recursive, settings added earlier win).
 *
 * @package    local_lticompletionsync
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settings_hook_standin {
    /** @var array Collected settings. */
    private array $settings = [];

    /**
     * Add settings; settings that are already present win (same rule as tool_mergeusers).
     *
     * @param array $settings
     */
    public function add_raw_settings(array $settings): void {
        $this->settings = array_replace_recursive($settings, $this->settings);
    }

    /**
     * The collected settings.
     *
     * @return array
     */
    public function get_raw(): array {
        return $this->settings;
    }
}
