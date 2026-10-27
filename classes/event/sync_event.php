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

namespace local_lticompletionsync\event;

/**
 * Base of the standardised transfer events (spec section 7).
 *
 * The event carries the correlation id, the connection id, the state version and a short code in "other"; never a
 * token, a JWT or a payload. The affected local user is the related user.
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class sync_event extends \core\event\base {
    /**
     * Init method.
     */
    protected function init() {
        $this->data['crud'] = static::crud_value();
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * CRUD letter of the event.
     *
     * @return string
     */
    protected static function crud_value(): string {
        return 'u';
    }

    /**
     * Short name of the event in the journal.
     *
     * @return string
     */
    public static function journal_name(): string {
        $parts = explode('\\', static::class);
        $class = end($parts);
        $name = array_search($class, \local_lticompletionsync\local\journal::CLASSES, true);
        return $name === false ? $class : $name;
    }

    /**
     * Localised name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_' . static::journal_name(), 'local_lticompletionsync');
    }

    /**
     * Non-localised description.
     *
     * @return string
     */
    public function get_description() {
        $user = $this->relateduserid ? " for the user with id '{$this->relateduserid}'" : '';
        return "LTI completion sync event '" . static::journal_name() . "'{$user}, correlation id '" .
            s($this->other['correlationid'] ?? '') . "', connection '" . (int) ($this->other['connectionid'] ?? 0) .
            "', version " . (int) ($this->other['version'] ?? 0) . '.';
    }

    /**
     * Validate the data.
     *
     * @throws \coding_exception
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['correlationid'])) {
            throw new \coding_exception('The \'correlationid\' value must be set in other.');
        }
    }

    /**
     * Mapping of "other" for backup/restore of logs.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return ['connectionid' => \core\event\base::NOT_MAPPED];
    }
}
