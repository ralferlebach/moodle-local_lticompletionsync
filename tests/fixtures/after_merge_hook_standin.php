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
 * Stand-in for \tool_mergeusers\hook\after_merged_all_tables.
 *
 * @package    local_lticompletionsync
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class after_merge_hook_standin {
    /** @var string[] Log lines. */
    public array $logs = [];
    /** @var string[] Error messages (any entry makes tool_mergeusers roll the merge back). */
    public array $errors = [];

    /**
     * Constructor.
     *
     * @param int $toid user that is kept
     * @param int $fromid user that is merged away
     */
    public function __construct(
        /** @var int user that is kept */
        public readonly int $toid,
        /** @var int user that is merged away */
        public readonly int $fromid
    ) {
    }

    /**
     * Add a log line.
     *
     * @param string $line
     */
    public function add_log(string $line): void {
        $this->logs[] = $line;
    }

    /**
     * Add an error message.
     *
     * @param string $line
     */
    public function add_error_message(string $line): void {
        $this->errors[] = $line;
    }
}
