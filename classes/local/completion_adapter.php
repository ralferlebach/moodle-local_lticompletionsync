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
 * Native completion adapter for mod_lti activities (the CS-G4 mechanism).
 *
 * Everything here goes through public core APIs that exist unchanged on Moodle 4.5 to 5.3 (see the gate tests in
 * tests/gates): the completion state of an activity with MANUAL completion tracking is set and revoked with
 * completion_info::update_state() without the override flag, so no capability of the acting user is needed and no
 * grade is read or written. Learner self-toggling is prevented server side by a capability prohibition of
 * moodle/course:togglecompletion for the authenticated-user role in the module context. The table
 * course_modules_completion is never written directly.
 *
 * Automatic completion tracking is not supported: the gate evidence shows that core recomputes the state from the
 * activity's own conditions, and an override needs moodle/course:overridecompletion of the acting user and does not
 * hold an INCOMPLETE state against later automatic triggers.
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class completion_adapter {
    /** @var string Capability that guards learner self-toggling of manual completion. */
    public const TOGGLE_CAPABILITY = 'moodle/course:togglecompletion';

    /** @var string Result: the state was changed. */
    public const RESULT_APPLIED = 'applied';
    /** @var string Result: the state already had the requested value. */
    public const RESULT_UNCHANGED = 'unchanged';

    /** @var string Unsupported: course completion disabled. */
    public const REASON_COMPLETION_DISABLED = 'completion_disabled';
    /** @var string Unsupported: activity is not mod_lti. */
    public const REASON_NOT_LTI = 'not_lti';
    /** @var string Unsupported: activity without completion tracking. */
    public const REASON_TRACKING_NONE = 'tracking_none';
    /** @var string Unsupported: activity with automatic completion tracking. */
    public const REASON_TRACKING_AUTOMATIC = 'tracking_automatic';
    /** @var string Unsupported: user is not tracked in the course. */
    public const REASON_USER_NOT_TRACKED = 'user_not_tracked';

    /**
     * Load the completion library (not autoloaded on 4.5).
     */
    private static function require_lib(): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
    }

    /**
     * Why native completion cannot be managed for an activity ('' when it can).
     *
     * @param \cm_info $cm
     * @return string reason code
     */
    public static function unsupported_reason(\cm_info $cm): string {
        self::require_lib();
        if ($cm->modname !== 'lti') {
            return self::REASON_NOT_LTI;
        }
        $completion = new \completion_info($cm->get_course());
        if (!$completion->is_enabled()) {
            return self::REASON_COMPLETION_DISABLED;
        }
        if ((int) $cm->completion === COMPLETION_TRACKING_NONE) {
            return self::REASON_TRACKING_NONE;
        }
        if ((int) $cm->completion !== COMPLETION_TRACKING_MANUAL) {
            return self::REASON_TRACKING_AUTOMATIC;
        }
        return '';
    }

    /**
     * Current native completion state (0 incomplete, 1 complete) of a user.
     *
     * COMPLETION_COMPLETE_FAIL counts as not completed (conservative mapping, see docs/DOCUMENTATION.md).
     *
     * @param \cm_info $cm
     * @param int $userid
     * @return int
     */
    public static function current_state(\cm_info $cm, int $userid): int {
        self::require_lib();
        $completion = new \completion_info($cm->get_course());
        $data = $completion->get_data($cm, false, $userid);
        return self::is_complete_state((int) $data->completionstate) ? 1 : 0;
    }

    /**
     * Who last overrode the completion state of a user (null when nobody did).
     *
     * @param \cm_info $cm
     * @param int $userid
     * @return int|null
     */
    public static function overridden_by(\cm_info $cm, int $userid): ?int {
        self::require_lib();
        $completion = new \completion_info($cm->get_course());
        $data = $completion->get_data($cm, false, $userid);
        return $data->overrideby === null ? null : (int) $data->overrideby;
    }

    /**
     * Whether a core completion state means completed.
     *
     * @param int $state COMPLETION_* constant
     * @return bool
     */
    public static function is_complete_state(int $state): bool {
        self::require_lib();
        return $state === COMPLETION_COMPLETE || $state === COMPLETION_COMPLETE_PASS;
    }

    /**
     * Set or revoke the native completion of a user through the public completion API.
     *
     * @param \cm_info $cm activity with manual completion tracking
     * @param int $userid
     * @param bool $completed
     * @return string RESULT_* constant
     * @throws \moodle_exception when the activity is not supported (reason code as error code)
     */
    public static function apply(\cm_info $cm, int $userid, bool $completed): string {
        self::require_lib();
        $reason = self::unsupported_reason($cm);
        if ($reason !== '') {
            throw new \moodle_exception('unsupported_' . $reason, 'local_lticompletionsync');
        }
        $completion = new \completion_info($cm->get_course());
        if (!$completion->is_tracked_user($userid)) {
            throw new \moodle_exception('unsupported_' . self::REASON_USER_NOT_TRACKED, 'local_lticompletionsync');
        }
        $before = $completion->get_data($cm, false, $userid);
        $wanted = $completed ? 1 : 0;
        if ((self::is_complete_state((int) $before->completionstate) ? 1 : 0) === $wanted) {
            // Already in the requested state (possibly by a local override, which the caller has resolved before).
            return self::RESULT_UNCHANGED;
        }
        $completion->update_state($cm, $completed ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE, $userid);
        return self::RESULT_APPLIED;
    }

    /**
     * The role whose self-toggling is prohibited (the authenticated-user role, which every logged-in user holds).
     *
     * @return int role id, 0 when the site has none configured
     */
    public static function lockdown_roleid(): int {
        global $CFG;
        return (int) ($CFG->defaultuserroleid ?? 0);
    }

    /** @var string Release outcome: the override that existed before the lockdown was written back. */
    public const RELEASE_RESTORED = 'restored';
    /** @var string Release outcome: there was no override before, the prohibition was removed. */
    public const RELEASE_REMOVED = 'removed';
    /** @var string Release outcome: somebody changed or removed the prohibition meanwhile; that decision is kept. */
    public const RELEASE_KEPT = 'kept_changed';
    /** @var string Release outcome: nothing to release. */
    public const RELEASE_NONE = 'none';

    /**
     * The explicit override of a role for the toggle capability in a context (null when there is none).
     *
     * @param \context $context
     * @param int $roleid
     * @return int|null CAP_* constant
     */
    public static function current_override(\context $context, int $roleid): ?int {
        global $DB;
        $permission = $DB->get_field('role_capabilities', 'permission', [
            'contextid' => $context->id, 'roleid' => $roleid, 'capability' => self::TOGGLE_CAPABILITY,
        ]);
        return $permission === false ? null : (int) $permission;
    }

    /**
     * Prohibit learner self-toggling in the module context.
     *
     * A CAP_PROHIBIT for the authenticated-user role is needed: a CAP_PREVENT for the student role alone does not
     * remove the CAP_ALLOW that the authenticated-user archetype holds (proved by the gate tests). An explicit override
     * that an administrator or teacher set before for that role in the activity is read first and returned, so that
     * release_lockdown() can write it back exactly. Overrides of other roles are never touched.
     *
     * @param \cm_info $cm
     * @return array [roleid (0 when none could be written), prior permission (int CAP_* or null when there was none)]
     */
    public static function lockdown(\cm_info $cm): array {
        $roleid = self::lockdown_roleid();
        if ($roleid <= 0) {
            return [0, null];
        }
        $context = \context_module::instance($cm->id);
        $prior = self::current_override($context, $roleid);
        if ($prior !== CAP_PROHIBIT) {
            assign_capability(self::TOGGLE_CAPABILITY, CAP_PROHIBIT, $roleid, $context->id, true);
        }
        $context->mark_dirty();
        return [$roleid, $prior];
    }

    /**
     * Remove a prohibition written by lockdown() and restore the override it replaced.
     *
     * Only acts while the current override is still the prohibition: when an administrator changed or removed it
     * during the management, that later decision is kept. Rows of 0.1.0 ($priorrecorded false) did not remember the
     * prior override; for them the prohibition is removed (the 0.1.0 behaviour).
     *
     * @param \context $context module context
     * @param int $roleid
     * @param bool $priorrecorded whether $prior is known
     * @param int|null $prior permission before the lockdown (null: there was no override)
     * @return string RELEASE_* constant
     */
    public static function release_lockdown(
        \context $context,
        int $roleid,
        bool $priorrecorded = false,
        ?int $prior = null
    ): string {
        if ($roleid <= 0) {
            return self::RELEASE_NONE;
        }
        if (self::current_override($context, $roleid) !== CAP_PROHIBIT) {
            return self::RELEASE_KEPT;
        }
        if ($priorrecorded && $prior !== null && $prior !== CAP_INHERIT) {
            if ($prior !== CAP_PROHIBIT) {
                assign_capability(self::TOGGLE_CAPABILITY, $prior, $roleid, $context->id, true);
            }
            $context->mark_dirty();
            return self::RELEASE_RESTORED;
        }
        unassign_capability(self::TOGGLE_CAPABILITY, $roleid, $context->id);
        $context->mark_dirty();
        return self::RELEASE_REMOVED;
    }

    /**
     * Tracked users (active enrolment, reported in completion) who can still toggle their own completion.
     *
     * Used to verify the lockdown: zero means the server-side guard is complete for the current learners. One COUNT
     * query and one bounded sample query (no materialisation of the course's users, no has_capability() per user).
     * Site administrators are not counted (they are never tracked through a role and bypass capability checks).
     *
     * @param \cm_info $cm
     * @param int $samplesize number of user ids to return as a sample
     * @return array count (int), sample (int[] user ids, at most $samplesize)
     */
    public static function users_who_can_toggle(\cm_info $cm, int $samplesize = 20): array {
        global $DB;
        $coursecontext = \context_course::instance($cm->course);
        $context = \context_module::instance($cm->id);
        $tracked = get_enrolled_with_capabilities_join($coursecontext, '', 'moodle/course:isincompletionreports', 0, true);
        $can = get_with_capability_join($context, self::TOGGLE_CAPABILITY, 'u.id');
        if ($tracked->cannotmatchanyrows || $can->cannotmatchanyrows) {
            return ['count' => 0, 'sample' => []];
        }
        $from = "FROM {user} u {$tracked->joins} {$can->joins} WHERE {$tracked->wheres} AND {$can->wheres}";
        $params = array_merge($tracked->params, $can->params);
        $count = (int) $DB->count_records_sql("SELECT COUNT(DISTINCT u.id) $from", $params);
        $sample = $count === 0 ? [] : array_map('intval', array_keys(
            $DB->get_records_sql("SELECT DISTINCT u.id $from ORDER BY u.id", $params, 0, $samplesize)
        ));
        return ['count' => $count, 'sample' => $sample];
    }

    /**
     * Whether the core hook after_cm_completion_updated exists (Moodle 5.0+). Informational only: it runs after a
     * change and cannot veto it, so the plugin relies on the course_module_completion_updated event on all versions.
     *
     * @return bool
     */
    public static function has_completion_hook(): bool {
        return class_exists('\\core_completion\\hook\\after_cm_completion_updated');
    }
}
