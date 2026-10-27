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

namespace local_lticompletionsync\local;

use local_lticompletionsync\local\receiver\identity_resolver;
use local_lticompletionsync\local\sender\outbox;

/**
 * Data of the reduced status view (works without tool_ltidiagnostics).
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class status_report {
    /**
     * Capability level of a connection, honestly declared (spec section 4, "Interoperabilitaetsstufen").
     *
     * @param \stdClass $con
     * @return array key (string identifier suffix), ok (bool)
     */
    public static function level(\stdClass $con): array {
        if ($con->role === connection::ROLE_RECEIVER) {
            return ['key' => 'receiver', 'ok' => (bool) $con->enabled];
        }
        switch ($con->partnerstatus) {
            case connection::PARTNER_PLUGIN:
                return ['key' => 'cooperative', 'ok' => true];
            case connection::PARTNER_NOPLUGIN:
                return ['key' => $con->agsprogress ? 'toolonly' : 'nopartner', 'ok' => false];
            case connection::PARTNER_DISABLED:
                return ['key' => 'partnerdisabled', 'ok' => false];
            case connection::PARTNER_ERROR:
                return ['key' => 'partnererror', 'ok' => false];
            default:
                return ['key' => 'unknown', 'ok' => false];
        }
    }

    /**
     * Row counts per status of a connection.
     *
     * @param \stdClass $con
     * @return array status => count
     */
    public static function counts(\stdClass $con): array {
        global $DB;
        $table = $con->role === connection::ROLE_SENDER ? outbox::TABLE : 'local_lticompletionsync_rcv';
        $rows = $DB->get_records_sql(
            "SELECT status, COUNT(1) AS c FROM {" . $table . "} WHERE connectionid = :id GROUP BY status",
            ['id' => $con->id]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[$row->status] = (int) $row->c;
        }
        ksort($out);
        return $out;
    }

    /**
     * Environment facts for diagnosis (no secrets).
     *
     * @return array label string identifier => value
     */
    public static function environment(): array {
        global $CFG;
        $diag = \core_component::get_component_directory('tool_ltidiagnostics');
        return [
            'env_moodle' => $CFG->release,
            'env_completionhook' => completion_adapter::has_completion_hook() ? get_string('yes') : get_string('no'),
            'env_toolkey' => get_config('enrol_lti', 'lti_13_kid') ? get_string('yes') : get_string('no'),
            'env_platformkey' => get_config('mod_lti', 'kid') ? get_string('yes') : get_string('no'),
            'env_pseudonyms' => identity_resolver::pseudonym_service_available() ? get_string('yes') : get_string('no'),
            'env_diagnostics' => ($diag && is_dir($diag)) ? get_string('yes') : get_string('no'),
            'env_endpoint' => protocol::endpoint_url(),
            'env_privatetargets' => config::allow_private_targets() ? get_string('yes') : get_string('no'),
        ];
    }

    /**
     * Render the journal as a table.
     *
     * @param \stdClass[] $rows
     * @param bool $links link correlation ids to the administration journal filter
     * @return string HTML
     */
    public static function journal_table(array $rows, bool $links = true): string {
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable local-lticompletionsync-journal';
        $table->head = [
            get_string('time'), get_string('event_name', 'local_lticompletionsync'),
            get_string('correlationid', 'local_lticompletionsync'), get_string('connection', 'local_lticompletionsync'),
            get_string('user'), get_string('version', 'local_lticompletionsync'), get_string('detail', 'local_lticompletionsync'),
        ];
        foreach ($rows as $row) {
            $table->data[] = [
                userdate($row->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
                s($row->eventname),
                $links ? \html_writer::link(
                    new \moodle_url('/local/lticompletionsync/index.php', ['correlationid' => $row->correlationid]),
                    s($row->correlationid)
                ) : s($row->correlationid),
                (int) $row->connectionid,
                (int) $row->userid ?: '-',
                (int) $row->version,
                s((string) $row->detail),
            ];
        }
        return \html_writer::table($table);
    }
}
