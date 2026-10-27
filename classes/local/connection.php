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

use local_lticompletionsync\local\net\url_guard;

/**
 * Connection register: which LTI 1.3 connection may send (tool side) or receive (platform side) completion.
 *
 * The role is decided per connection, never once for the whole site: the same installation can be sender for one
 * platform, receiver for one tool and both for itself (loopback).
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class connection {
    /** @var string Table. */
    public const TABLE = 'local_lticompletionsync_con';
    /** @var string Role on the tool side (enrol_lti published resources). */
    public const ROLE_SENDER = 'sender';
    /** @var string Role on the platform side (mod_lti activities). */
    public const ROLE_RECEIVER = 'receiver';

    /** @var string Conflict policy: the provider state is re-asserted. */
    public const POLICY_PROVIDER = 'provider';
    /** @var string Conflict policy: a local override is kept and reported. */
    public const POLICY_LOCAL = 'local';

    /** @var string Partner status: not yet checked. */
    public const PARTNER_UNKNOWN = 'unknown';
    /** @var string Partner status: plugin answered the handshake. */
    public const PARTNER_PLUGIN = 'plugin';
    /** @var string Partner status: plugin present, connection not enabled there. */
    public const PARTNER_DISABLED = 'disabled';
    /** @var string Partner status: no plugin at the endpoint. */
    public const PARTNER_NOPLUGIN = 'noplugin';
    /** @var string Partner status: check failed. */
    public const PARTNER_ERROR = 'error';

    /**
     * Fetch a connection.
     *
     * @param int $id
     * @return \stdClass|null
     */
    public static function get(int $id): ?\stdClass {
        global $DB;
        $row = $DB->get_record(self::TABLE, ['id' => $id]);
        return $row ?: null;
    }

    /**
     * All connections, sender first.
     *
     * @return \stdClass[]
     */
    public static function all(): array {
        global $DB;
        return $DB->get_records(self::TABLE, null, 'role DESC, id ASC');
    }

    /**
     * The receiver connection (enabled or not) of an LTI 1.3 tool type identified by its client id.
     *
     * @param string $clientid
     * @return \stdClass|null connection with the extra field typeid
     */
    public static function receiver_for_clientid(string $clientid): ?\stdClass {
        global $DB;
        if ($clientid === '' || strlen($clientid) > 1333) {
            return null;
        }
        $sql = "SELECT c.*
                  FROM {" . self::TABLE . "} c
                  JOIN {lti_types} t ON t.id = c.ltitypeid
                 WHERE c.role = :role AND t.clientid = :clientid AND t.ltiversion = :ver";
        $rows = $DB->get_records_sql($sql, ['role' => self::ROLE_RECEIVER, 'clientid' => $clientid, 'ver' => '1.3.0']);
        if (count($rows) !== 1) {
            return null;
        }
        return reset($rows);
    }

    /**
     * The enabled sender connection of an enrol_lti deployment.
     *
     * @param int $deploymentid enrol_lti_deployment.id
     * @return \stdClass|null
     */
    public static function sender_for_deployment(int $deploymentid): ?\stdClass {
        global $DB;
        $row = $DB->get_record(self::TABLE, ['role' => self::ROLE_SENDER, 'deploymentid' => $deploymentid, 'enabled' => 1]);
        return $row ?: null;
    }

    /**
     * Validate the data of a connection before saving.
     *
     * @param \stdClass $data
     * @return string[] field => error string identifier
     */
    public static function validate(\stdClass $data): array {
        global $DB;
        $errors = [];
        if ($data->role === self::ROLE_RECEIVER) {
            $type = $DB->get_record('lti_types', ['id' => (int) ($data->ltitypeid ?? 0)]);
            if (!$type || $type->ltiversion !== '1.3.0' || empty($type->clientid)) {
                $errors['ltitypeid'] = 'error_type';
            }
        } else if ($data->role === self::ROLE_SENDER) {
            $dep = $DB->get_record('enrol_lti_deployment', ['id' => (int) ($data->deploymentid ?? 0)]);
            if (!$dep) {
                $errors['deploymentid'] = 'error_deployment';
            }
            $reason = url_guard::check((string) ($data->endpointurl ?? ''), config::allow_private_targets());
            if ($reason !== url_guard::OK) {
                $errors['endpointurl'] = 'error_url_' . $reason;
            } else if ($dep) {
                $reg = $DB->get_record('enrol_lti_app_registration', ['id' => $dep->platformid]);
                $issuerhost = $reg ? strtolower((string) parse_url($reg->platformid, PHP_URL_HOST)) : '';
                $endpointhost = strtolower((string) parse_url((string) $data->endpointurl, PHP_URL_HOST));
                if ($issuerhost === '' || $issuerhost !== $endpointhost) {
                    // The endpoint must live on the host of the registered issuer: the response is verified with the
                    // issuer's key set, and a foreign host would be an SSRF vector.
                    $errors['endpointurl'] = 'error_url_issuerhost';
                }
            }
        } else {
            $errors['role'] = 'error_role';
        }
        if (isset($data->conflictpolicy) && !in_array($data->conflictpolicy, [self::POLICY_PROVIDER, self::POLICY_LOCAL], true)) {
            $errors['conflictpolicy'] = 'error_policy';
        }
        return $errors;
    }

    /**
     * Create or update a connection (administrative confirmation is the enabled flag).
     *
     * @param \stdClass $data
     * @return int id
     * @throws \moodle_exception on invalid data
     */
    public static function save(\stdClass $data): int {
        global $DB, $USER;
        $errors = self::validate($data);
        if ($errors) {
            throw new \moodle_exception(reset($errors), config::COMPONENT);
        }
        $now = time();
        $row = (object) [
            'role' => $data->role,
            'enabled' => empty($data->enabled) ? 0 : 1,
            'ltitypeid' => $data->role === self::ROLE_RECEIVER ? (int) $data->ltitypeid : 0,
            'registrationid' => 0,
            'deploymentid' => $data->role === self::ROLE_SENDER ? (int) $data->deploymentid : 0,
            'issuer' => null,
            'clientid' => null,
            'endpointurl' => $data->role === self::ROLE_SENDER ? trim($data->endpointurl) : null,
            'automanage' => empty($data->automanage) ? 0 : 1,
            'lockdown' => !isset($data->lockdown) || !empty($data->lockdown) ? 1 : 0,
            'conflictpolicy' => $data->conflictpolicy ?? self::POLICY_PROVIDER,
            'agsprogress' => empty($data->agsprogress) ? 0 : 1,
            'usermodified' => (int) $USER->id,
            'timemodified' => $now,
        ];
        if ($data->role === self::ROLE_SENDER) {
            $dep = $DB->get_record('enrol_lti_deployment', ['id' => $row->deploymentid], '*', MUST_EXIST);
            $reg = $DB->get_record('enrol_lti_app_registration', ['id' => $dep->platformid], '*', MUST_EXIST);
            $row->registrationid = (int) $reg->id;
            $row->issuer = $reg->platformid;
            $row->clientid = $reg->clientid;
        } else {
            $row->clientid = $DB->get_field('lti_types', 'clientid', ['id' => $row->ltitypeid]);
        }
        if (!empty($data->id)) {
            $row->id = (int) $data->id;
            $existing = self::get($row->id);
            if (!$existing) {
                throw new \moodle_exception('error_connection', config::COMPONENT);
            }
            if ($existing->endpointurl !== $row->endpointurl) {
                $row->partnerstatus = self::PARTNER_UNKNOWN;
                $row->partnerversion = null;
                $row->timechecked = 0;
            }
            $DB->update_record(self::TABLE, $row);
            return $row->id;
        }
        $row->timecreated = $now;
        $row->partnerstatus = self::PARTNER_UNKNOWN;
        return (int) $DB->insert_record(self::TABLE, $row);
    }

    /**
     * Delete a connection with its state rows (the native completion of the platform is not touched).
     *
     * @param int $id
     */
    public static function delete(int $id): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records('local_lticompletionsync_out', ['connectionid' => $id]);
        $DB->delete_records('local_lticompletionsync_rcv', ['connectionid' => $id]);
        $DB->delete_records('local_lticompletionsync_jti', ['connectionid' => $id]);
        $DB->delete_records(self::TABLE, ['id' => $id]);
        $transaction->allow_commit();
    }

    /**
     * Human readable label of a connection (no secrets).
     *
     * @param \stdClass $con
     * @return string
     */
    public static function label(\stdClass $con): string {
        global $DB;
        if ($con->role === self::ROLE_RECEIVER) {
            $name = $DB->get_field('lti_types', 'name', ['id' => $con->ltitypeid]);
            return get_string('label_receiver', config::COMPONENT, format_string((string) $name));
        }
        $name = $DB->get_field('enrol_lti_app_registration', 'name', ['id' => $con->registrationid]);
        return get_string('label_sender', config::COMPONENT, format_string((string) $name));
    }
}
