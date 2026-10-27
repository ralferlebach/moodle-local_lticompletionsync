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
 * Uninstall cleanup of local_lticompletionsync.
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Remove what core does not remove: the learner lockdown of managed activities.
 *
 * The lockdown is a role override of the core capability moodle/course:togglecompletion (CAP_PROHIBIT for the
 * authenticated-user role) in each managed activity. Core only removes the plugin's own capabilities, so without this
 * step learners would stay unable to mark these activities forever. Called by core before the plugin tables are dropped.
 *
 * @return bool
 */
function xmldb_local_lticompletionsync_uninstall() {
    \local_lticompletionsync\local\receiver\activity_manager::release_all_lockdowns();
    return true;
}
