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
                if (!$con->agsprogress) {
                    return ['key' => 'nopartner', 'ok' => false];
                }
                // Judge the platform by the AGS capabilities it actually granted at launch.
                $ags = \local_lticompletionsync\local\sender\ags_progress::capability_set($con);
                return ['key' => $ags['progress'] > 0 ? 'toolonly' : 'nopartnernoags', 'ok' => false, 'ags' => $ags];
            case connection::PARTNER_DISABLED:
                return ['key' => 'partnerdisabled', 'ok' => false];
            case connection::PARTNER_ERROR:
                return ['key' => 'partnererror', 'ok' => false];
            default:
                return ['key' => 'unknown', 'ok' => false];
        }
    }

    /**
     * Live outcome report per connection (CS-06): success, failure, revocation and retries with their causes, from
     * the plugin's own journal (works without tool_ltidiagnostics). One grouped query; causes are codes only.
     *
     * Sender rows: success = acknowledged "applied", failed = dead letters by cause, revoked = revocation versions
     * queued, retried = scheduled retries by cause. Receiver rows: success = completions applied, revoked =
     * completions revoked, failed = rejected messages by reason, retried = repeated deliveries answered from the stored
     * state (duplicates) and outdated versions refused.
     *
     * @param int $window seconds back from now
     * @param int|null $now
     * @return array connection id => [role, label, success, revoked, failed (cause => n), retried (cause => n)]
     */
    public static function outcomes(int $window, ?int $now = null): array {
        global $DB;
        $since = ($now ?? time()) - $window;
        $rows = $DB->get_recordset_sql(
            "SELECT connectionid, eventname, detail, COUNT(1) AS c
               FROM {" . journal::TABLE . "}
              WHERE timecreated >= :since
           GROUP BY connectionid, eventname, detail",
            ['since' => $since]
        );
        $report = [];
        foreach (connection::all() as $con) {
            $report[(int) $con->id] = ['role' => $con->role, 'label' => connection::label($con), 'success' => 0,
                'revoked' => 0, 'failed' => [], 'retried' => []];
        }
        foreach ($rows as $row) {
            $cid = (int) $row->connectionid;
            if (!isset($report[$cid])) {
                continue;
            }
            $n = (int) $row->c;
            $detail = (string) $row->detail;
            $r = &$report[$cid];
            if ($r['role'] === connection::ROLE_SENDER) {
                if ($row->eventname === 'acknowledged' && $detail === 'applied') {
                    $r['success'] += $n;
                } else if ($row->eventname === 'queued' && $detail === 'state=0') {
                    $r['revoked'] += $n;
                } else if ($row->eventname === 'dead_letter') {
                    $r['failed'][$detail] = ($r['failed'][$detail] ?? 0) + $n;
                } else if ($row->eventname === 'retry_scheduled') {
                    $cause = explode(':+', $detail)[0];
                    $r['retried'][$cause] = ($r['retried'][$cause] ?? 0) + $n;
                }
            } else {
                if ($row->eventname === 'completion_applied') {
                    $r['success'] += $n;
                } else if ($row->eventname === 'completion_revoked') {
                    $r['revoked'] += $n;
                } else if ($row->eventname === 'reconciled' && strpos($detail, 'rejected:') === 0) {
                    $cause = substr($detail, 9);
                    $r['failed'][$cause] = ($r['failed'][$cause] ?? 0) + $n;
                } else if ($row->eventname === 'reconciled' && ($detail === 'duplicate' || strpos($detail, 'stale:') === 0)) {
                    $cause = $detail === 'duplicate' ? 'duplicate' : 'stale_version';
                    $r['retried'][$cause] = ($r['retried'][$cause] ?? 0) + $n;
                }
            }
            unset($r);
        }
        $rows->close();
        foreach ($report as &$r) {
            ksort($r['failed']);
            ksort($r['retried']);
        }
        unset($r);
        return $report;
    }

    /**
     * Render the outcome report.
     *
     * @param array $report from outcomes()
     * @return string HTML
     */
    public static function outcomes_table(array $report): string {
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable local-lticompletionsync-report';
        $table->head = [
            get_string('connection', 'local_lticompletionsync'), get_string('report_success', 'local_lticompletionsync'),
            get_string('report_revoked', 'local_lticompletionsync'), get_string('report_failed', 'local_lticompletionsync'),
            get_string('report_retried', 'local_lticompletionsync'),
        ];
        $causes = function (array $list): string {
            $out = [];
            foreach ($list as $cause => $n) {
                $out[] = s($cause === '' ? '-' : $cause) . ': ' . (int) $n;
            }
            return $out ? implode(\html_writer::empty_tag('br'), $out) : '0';
        };
        foreach ($report as $r) {
            $table->data[] = [s($r['label']), (int) $r['success'], (int) $r['revoked'], $causes($r['failed']),
                $causes($r['retried'])];
        }
        return \html_writer::table($table);
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
            'env_pseudonyms' => identity_resolver::pseudonym_service_available() ? get_string('yes') :
                get_string('no') . ' (API ' . identity_resolver::translator_api_version() . ')',
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
