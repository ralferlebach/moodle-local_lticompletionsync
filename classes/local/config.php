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

/**
 * Typed access to the plugin settings with safe bounds.
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class config {
    /** @var string Frankenstyle component. */
    public const COMPONENT = 'local_lticompletionsync';

    /**
     * A bounded integer setting.
     *
     * @param string $name
     * @param int $default
     * @param int $min
     * @param int $max
     * @return int
     */
    private static function int(string $name, int $default, int $min, int $max): int {
        $value = get_config(self::COMPONENT, $name);
        if ($value === false || $value === '' || !is_numeric($value)) {
            return $default;
        }
        return max($min, min($max, (int) $value));
    }

    /**
     * Whether outgoing requests may target private or loopback addresses and plain http (test labs only).
     *
     * @return bool
     */
    public static function allow_private_targets(): bool {
        return (bool) get_config(self::COMPONENT, 'allowprivatetargets');
    }

    /**
     * Delivery attempts before a message goes to the dead letter state.
     *
     * @return int
     */
    public static function max_attempts(): int {
        return self::int('maxattempts', 8, 1, 50);
    }

    /**
     * First retry delay in seconds (doubled per attempt).
     *
     * @return int
     */
    public static function backoff_base(): int {
        return self::int('backoffbase', 60, 1, 86400);
    }

    /**
     * Maximum retry delay in seconds.
     *
     * @return int
     */
    public static function backoff_max(): int {
        return self::int('backoffmax', 21600, 60, 7 * 86400);
    }

    /**
     * Life time of a signed message in seconds.
     *
     * @return int
     */
    public static function message_ttl(): int {
        return self::int('messagettl', 120, 30, 600);
    }

    /**
     * Accepted clock skew in seconds.
     *
     * @return int
     */
    public static function clock_skew(): int {
        return self::int('clockskew', 60, 0, 300);
    }

    /**
     * Retention of the transfer journal in days.
     *
     * @return int
     */
    public static function journal_retention_days(): int {
        return self::int('journalretention', 90, 1, 3650);
    }

    /**
     * Maximum number of outbox rows one delivery run (drain task, sweep) processes.
     *
     * @return int
     */
    public static function batch_limit(): int {
        return self::int('batchlimit', 500, 10, 10000);
    }

    /**
     * Maximum seconds one delivery run spends before leaving the rest to the next run.
     *
     * @return int
     */
    public static function time_budget(): int {
        return self::int('timebudget', 120, 5, 1800);
    }

    /**
     * Exponential backoff delay for an attempt number (1 = first retry), without jitter.
     *
     * @param int $attempt
     * @return int seconds
     */
    public static function backoff_delay(int $attempt): int {
        $attempt = max(1, $attempt);
        $delay = self::backoff_base() * (2 ** min(20, $attempt - 1));
        return (int) min(self::backoff_max(), $delay);
    }
}
