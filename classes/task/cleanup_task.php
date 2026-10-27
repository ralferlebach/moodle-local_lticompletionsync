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

use local_lticompletionsync\local\journal;
use local_lticompletionsync\local\receiver\endpoint;

/**
 * Scheduled cleanup: expired replay entries and journal rows older than the retention period.
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_task extends \core\task\scheduled_task {
    /**
     * Name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_cleanup', 'local_lticompletionsync');
    }

    /**
     * Run.
     */
    public function execute() {
        endpoint::purge_jti();
        mtrace('local_lticompletionsync: purged ' . journal::purge_old() . ' journal rows');
    }
}
