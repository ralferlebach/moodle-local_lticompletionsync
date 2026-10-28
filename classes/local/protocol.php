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
 * Constants of the documented LTI extension "lticompletionsync v1" (not an AGS endpoint).
 *
 * Request: HTTP POST of a compact JWS (Content-Type application/jwt) to the platform's endpoint
 * /local/lticompletionsync/endpoint.php, signed RS256 with the tool key of enrol_lti (the key the platform already
 * trusts for this LTI 1.3 tool). Claims: iss = sub = client id, aud = endpoint URL, iat, exp, jti and the private
 * claim {@see self::CLAIM}. Response: a compact JWS signed with the platform key of mod_lti (verifiable with the
 * platform key set registered in enrol_lti), aud = client id, claim {@see self::CLAIM} with in_reply_to = request jti.
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class protocol {
    /** @var string Name of the private claim carrying the message. */
    public const CLAIM = 'lticompletionsync';
    /** @var int Protocol version. */
    public const VERSION = 1;
    /** @var string Media type of request and response. */
    public const CONTENT_TYPE = 'application/jwt';
    /** @var int Maximum request size in bytes. */
    public const MAX_BODY = 16384;

    /** @var string Message: capability handshake. */
    public const TYPE_HELLO = 'hello';
    /** @var string Message: desired completion state. */
    public const TYPE_COMPLETION = 'completion';
    /** @var string Message: query the stored state. */
    public const TYPE_STATUS = 'status';

    /** @var string Provider state completed. */
    public const STATE_COMPLETED = 'completed';
    /** @var string Provider state not completed. */
    public const STATE_INCOMPLETE = 'incomplete';

    /** @var string Receiver status: persisted, not yet checked. */
    public const ACK_RECEIVED = 'received';
    /** @var string Receiver status: identity and activity resolved. */
    public const ACK_VALIDATED = 'validated';
    /** @var string Receiver status: native completion has the provider state. */
    public const ACK_APPLIED = 'applied';
    /** @var string Receiver status: refused (see reason). */
    public const ACK_REJECTED = 'rejected';
    /** @var string Receiver status: a local override wins by the receiver's conflict policy. */
    public const ACK_CONFLICT = 'conflict';

    /** @var string[] Rejection reasons after which a retry of the same version cannot succeed. */
    public const PERMANENT_REASONS = [
        'unknown_resource_link', 'wrong_tool', 'activity_not_managed', 'unsupported_not_lti',
        'unsupported_completion_disabled', 'unsupported_tracking_none', 'unsupported_tracking_automatic',
        'unsupported_user_not_tracked', 'identity_unresolved', 'pseudonym_api_unsupported', 'bad_message', 'stale_version',
        'wrong_deployment',
    ];

    /**
     * Map an LTI AGS activityProgress value to the binary completion state (spec section 6).
     *
     * @param string $progress Initialized, Started, InProgress, Submitted or Completed
     * @return int 1 completed, 0 not completed
     */
    public static function progress_to_state(string $progress): int {
        return $progress === 'Completed' ? 1 : 0;
    }

    /**
     * Map the binary state to the wire value.
     *
     * @param int $state
     * @return string
     */
    public static function state_name(int $state): string {
        return $state ? self::STATE_COMPLETED : self::STATE_INCOMPLETE;
    }

    /**
     * Map the wire value to the binary state.
     *
     * @param string $name
     * @return int|null null for an unknown value
     */
    public static function state_value(string $name): ?int {
        if ($name === self::STATE_COMPLETED) {
            return 1;
        }
        if ($name === self::STATE_INCOMPLETE) {
            return 0;
        }
        return null;
    }

    /**
     * URL of the receiver endpoint of this site.
     *
     * @return string
     */
    public static function endpoint_url(): string {
        return (new \moodle_url('/local/lticompletionsync/endpoint.php'))->out(false);
    }

    /**
     * Whether a correlation id has the expected shape (UUID).
     *
     * @param string $id
     * @return bool
     */
    public static function valid_correlation_id(string $id): bool {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id);
    }
}
