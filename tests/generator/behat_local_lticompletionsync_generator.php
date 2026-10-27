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
 * Behat data generator of local_lticompletionsync.
 *
 * @package    local_lticompletionsync
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_lticompletionsync_generator extends behat_generator_base {
    /**
     * Creatable entities.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'receiver connections' => [
                'singular' => 'receiver connection',
                'datagenerator' => 'receiver_connection',
                'required' => ['tool'],
                'switchids' => ['tool' => 'ltitypeid'],
            ],
        ];
    }

    /**
     * Resolve an LTI tool type by name.
     *
     * @param string $name
     * @return int
     */
    protected function get_tool_id(string $name): int {
        global $DB;
        return (int) $DB->get_field('lti_types', 'id', ['name' => $name], MUST_EXIST);
    }
}
