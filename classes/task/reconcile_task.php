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

use local_lticompletionsync\local\sender\reconciler;

/**
 * Ad hoc full reconcile of one sender connection (administrator action) or of all of them.
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reconcile_task extends \core\task\adhoc_task {
    /**
     * Run.
     */
    public function execute() {
        $data = $this->get_custom_data();
        \local_lticompletionsync\local\sender\source::reset_caches();
        $force = !empty($data->force);
        $count = reconciler::full((int) ($data->connectionid ?? 0), $force);
        mtrace('local_lticompletionsync: ' . ($force ? 'force resync' : 'full reconcile') . ' queued ' . $count .
            ' new versions');
    }
}
