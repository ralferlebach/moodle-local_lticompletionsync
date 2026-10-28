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

use local_lticompletionsync\local\receiver\processor;
use local_lticompletionsync\local\sender\outbox;

/**
 * Integration with the account merge tool tool_mergeusers (same pattern as the family's siblings).
 *
 * The plugin's user tables are taken out of the generic table processing (`exceptions`, keyed by table name: a
 * numeric key would replace one of tool_mergeusers' default entries in its array_replace_recursive() layering) and are
 * merged here, inside the merge transaction, through the hook after_merged_all_tables:
 *
 * - outbox (tool side, one row per connection + resource link + user): the merged-away user's rows move to the kept
 *   user; when both have a row for the same target, the row with the higher version survives (its version stays the
 *   highest of both sequences, so the next version the sweep records is newer than anything the platform has seen),
 *   the other row is deleted - no duplicate target, no backward version;
 * - receiver state (platform side, one row per connection + activity + user): same rule with the provider time stamp
 *   (then the version) deciding; the surviving row keeps its subject hash, so a later message of the other account's
 *   subject is ordered by the provider time stamp instead of a foreign version counter (processor);
 * - journal: rows move to the kept user.
 *
 * Any failure is reported as an error of the merge, which makes tool_mergeusers roll the whole merge back. Callbacks
 * are typed with `object`: no dependency on tool_mergeusers.
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class merge_integration {
    /** @var array Unique index columns besides userid per table merged here. */
    public const TABLES = [
        outbox::TABLE => ['connectionid', 'resourcelinkid'],
        processor::TABLE => ['connectionid', 'cmid'],
        journal::TABLE => [],
    ];

    /**
     * Database settings for tool_mergeusers.
     *
     * @return array
     */
    public static function db_settings(): array {
        $settings = ['exceptions' => [], 'compoundindexes' => []];
        foreach (self::TABLES as $table => $otherfields) {
            $settings['exceptions'][$table] = $table;
            if ($otherfields) {
                // Declared as well, so that a site re-enabling a table at least gets tool_mergeusers' row-by-row
                // resolution instead of its fallback (which deletes every row of the merged-away user).
                $settings['compoundindexes'][$table] = ['userfield' => ['userid'], 'otherfields' => $otherfields];
            }
        }
        return $settings;
    }

    /**
     * Callback for \tool_mergeusers\hook\add_settings_before_merging.
     *
     * @param object $hook offers add_raw_settings(array)
     */
    public static function add_settings_before_merging(object $hook): void {
        $hook->add_raw_settings(self::db_settings());
    }

    /**
     * Callback for \tool_mergeusers\hook\after_merged_all_tables (inside the merge transaction).
     *
     * @param object $hook offers toid, fromid, add_log(string), add_error_message(string)
     */
    public static function after_merged_all_tables(object $hook): void {
        try {
            $c = self::merge((int) $hook->toid, (int) $hook->fromid);
            $hook->add_log('local_lticompletionsync: outbox moved ' . $c['out_moved'] . ', joined ' . $c['out_joined'] .
                '; receiver states moved ' . $c['rcv_moved'] . ', joined ' . $c['rcv_joined'] . '; journal rows ' .
                $c['log_moved']);
        } catch (\Throwable $e) {
            $hook->add_error_message('local_lticompletionsync: merging the completion sync states failed (' .
                get_class($e) . ': ' . $e->getMessage() . ')');
        }
    }

    /**
     * Move the rows of a merged-away user to the kept user without duplicate targets.
     *
     * @param int $toid user that is kept
     * @param int $fromid user that is merged away
     * @return array counts out_moved, out_joined, rcv_moved, rcv_joined, log_moved
     */
    public static function merge(int $toid, int $fromid): array {
        global $DB;
        if ($toid <= 0 || $fromid <= 0 || $toid === $fromid) {
            throw new \coding_exception('Invalid pair of users for a merge');
        }
        $counts = ['out_moved' => 0, 'out_joined' => 0, 'rcv_moved' => 0, 'rcv_joined' => 0, 'log_moved' => 0];
        [$counts['out_moved'], $counts['out_joined']] = self::merge_table(
            outbox::TABLE,
            $toid,
            $fromid,
            fn($row) => [(int) $row->version, (int) $row->statetime]
        );
        [$counts['rcv_moved'], $counts['rcv_joined']] = self::merge_table(
            processor::TABLE,
            $toid,
            $fromid,
            fn($row) => [(int) $row->remotetime, (int) $row->remoteversion]
        );
        $counts['log_moved'] = $DB->count_records(journal::TABLE, ['userid' => $fromid]);
        $DB->set_field(journal::TABLE, 'userid', $toid, ['userid' => $fromid]);
        return $counts;
    }

    /**
     * Merge one table with a unique (other fields + userid) index.
     *
     * @param string $table
     * @param int $toid
     * @param int $fromid
     * @param callable $rank row => comparable array; the higher rank survives a conflict (the kept user's row on a tie)
     * @return int[] [moved, joined]
     */
    private static function merge_table(string $table, int $toid, int $fromid, callable $rank): array {
        global $DB;
        $fields = self::TABLES[$table];
        $key = function (\stdClass $row) use ($fields): string {
            return implode('|', array_map(fn($f) => (string) $row->$f, $fields));
        };
        $kept = [];
        foreach ($DB->get_records($table, ['userid' => $toid], 'id') as $row) {
            $kept[$key($row)] = $row;
        }
        $moved = 0;
        $joined = 0;
        foreach ($DB->get_records($table, ['userid' => $fromid], 'id') as $row) {
            $k = $key($row);
            if (!isset($kept[$k])) {
                $DB->set_field($table, 'userid', $toid, ['id' => $row->id]);
                $kept[$k] = $row;
                $moved++;
                continue;
            }
            $joined++;
            if ($rank($row) > $rank($kept[$k])) {
                // The merged-away user's row is newer: it replaces the kept user's row for this target.
                $DB->delete_records($table, ['id' => $kept[$k]->id]);
                $DB->set_field($table, 'userid', $toid, ['id' => $row->id]);
                $kept[$k] = $row;
            } else {
                $DB->delete_records($table, ['id' => $row->id]);
            }
        }
        return [$moved, $joined];
    }
}
