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
 * Data generator of local_lticompletionsync.
 *
 * @package    local_lticompletionsync
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_lticompletionsync_generator extends component_generator_base {
    /**
     * Create a receiver connection for an LTI 1.3 tool type.
     *
     * @param array $data ltitypeid, enabled, automanage, lockdown, conflictpolicy
     * @return stdClass
     */
    public function create_receiver_connection(array $data): stdClass {
        $id = \local_lticompletionsync\local\connection::save((object) ($data + [
            'role' => \local_lticompletionsync\local\connection::ROLE_RECEIVER,
            'enabled' => 1,
            'automanage' => 0,
            'lockdown' => 1,
            'conflictpolicy' => \local_lticompletionsync\local\connection::POLICY_PROVIDER,
        ]));
        return \local_lticompletionsync\local\connection::get($id);
    }
}
