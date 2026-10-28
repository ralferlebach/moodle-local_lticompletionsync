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

use local_lticompletionsync\local\sender\dispatcher;

/**
 * Ad hoc delivery of one outbox row (queued by the observer right after a change) or of all due rows.
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dispatch_task extends \core\task\adhoc_task {
    /**
     * Run.
     */
    public function execute() {
        $data = $this->get_custom_data();
        \local_lticompletionsync\local\sender\source::reset_caches();
        $dispatcher = new dispatcher();
        if (!empty($data->outid)) {
            $status = $dispatcher->deliver((int) $data->outid);
            mtrace('local_lticompletionsync: row ' . (int) $data->outid . ' -> ' . ($status === '' ? 'skipped' : $status));
            return;
        }
        mtrace('local_lticompletionsync: delivered ' . $dispatcher->deliver_due() . ' due rows');
    }
}
