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

namespace local_lticompletionsync\local\receiver;

/**
 * Resolves the LTI subject (`sub`, as the platform sent it at launch) back to a local user.
 *
 * Without pseudonymisation mod_lti sends the local user id. When ltisource_ltipseudonym is installed and its policy
 * pseudonymises the tool/course/activity, the pseudonym is translated through that plugin's read-only translator
 * (class_exists-guarded, no hard dependency, no SQL on its tables). Retired or foreign pseudonyms (rotation, other
 * scope, other tool) and any failure of the translator fail closed.
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class identity_resolver {
    /** @var string Translator of ltisource_ltipseudonym. */
    private const TRANSLATOR = '\\ltisource_ltipseudonym\\local\\proxy\\translator';

    /** @var string How the last identity was resolved: local_id or pseudonym. */
    public $method = '';

    /**
     * Whether the pseudonym plugin offers the read-only translation service.
     *
     * @return bool
     */
    public static function pseudonym_service_available(): bool {
        return class_exists(self::TRANSLATOR)
            && method_exists(self::TRANSLATOR, 'context_for')
            && method_exists(self::TRANSLATOR, 'user_of');
    }

    /**
     * Resolve a subject.
     *
     * @param \stdClass $type lti_types record
     * @param int $courseid
     * @param int $cmid
     * @param string $sub
     * @return int local user id
     * @throws rejection identity_unresolved
     */
    public function resolve(\stdClass $type, int $courseid, int $cmid, string $sub): int {
        global $DB;
        $this->method = '';
        if ($sub === '' || strlen($sub) > 255) {
            throw new rejection('identity_unresolved');
        }
        if (self::pseudonym_service_available()) {
            $class = self::TRANSLATOR;
            try {
                $translator = new $class();
                $ctx = $translator->context_for($type, $courseid, $cmid);
                if ($ctx && $ctx->pseudonymising()) {
                    $userid = (int) $translator->user_of($ctx, $sub, 'lticompletionsync');
                    $this->method = 'pseudonym';
                    return $this->checked($userid);
                }
            } catch (rejection $e) {
                throw $e;
            } catch (\Throwable $e) {
                // A miss (retired pseudonym after a rotation, other scope or tool) or an incompatible translator.
                throw new rejection('identity_unresolved');
            }
        }
        if (!ctype_digit($sub)) {
            throw new rejection('identity_unresolved');
        }
        $this->method = 'local_id';
        return $this->checked((int) $sub);
    }

    /**
     * The user must exist, be active and not be the guest.
     *
     * @param int $userid
     * @return int
     * @throws rejection
     */
    private function checked(int $userid): int {
        global $DB;
        if ($userid <= 0 || isguestuser($userid)) {
            throw new rejection('identity_unresolved');
        }
        $user = $DB->get_record('user', ['id' => $userid], 'id, deleted, suspended');
        if (!$user || $user->deleted) {
            throw new rejection('identity_unresolved');
        }
        return (int) $user->id;
    }
}
