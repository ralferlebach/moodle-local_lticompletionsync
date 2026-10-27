# CS-G4 interim gate result (P7, local_lticompletionsync) – 2026-10-26

Evidence: `tests/gates/cs_g4_gate_test.php` (14 tests), run with `ltirun <tree> pgsql local_lticompletionsync phpunit --filter cs_g4_gate_test`.

| Tree | DB | Result |
|---|---|---|
| Moodle 4.5.15+ (PHPUnit 9.6, PHP 8.3) | PostgreSQL 16 | OK, 14 tests, 54 assertions |
| Moodle 5.3+ (PHPUnit 11.5, PHP 8.3) | PostgreSQL 17 | OK, 14 tests, 57 assertions (1 PHPUnit deprecation = docblock annotations, accepted by COMMON) |

MariaDB, 5.0/5.1/5.2: not yet executed (pending full matrix).

## Findings (identical on 4.5 and 5.3)

1. **Step 1 – positive.** For a mod_lti activity with **manual** completion tracking, `completion_info::update_state($cm, COMPLETE|INCOMPLETE, $userid)` (no override flag) sets and revokes the native completion of one user from server-side code with **no capability of the acting user** (tested with `setUser(0)`), fires `course_module_completion_updated`, is idempotent, and leaves `grade_grades`, `grade_grades_history` and `grade_items` byte-identical (existing grade 42 preserved). Revocation survives `purge_all_caches()` + `completion_regular_task`. Grade changes on a manual activity never touch its completion (`inform_grade_changed()` bails out for manual tracking).
2. **Step 2 – positive with a documented side effect.** Both learner entry points (`core_completion_external::update_activity_completion_status_manually`, `course/togglecompletion.php`) `require_capability('moodle/course:togglecompletion', module context)` before `update_state()`. The capability's only archetype is the *authenticated user* role, so a CAP_PREVENT for `student` is **not** enough (proved); a **CAP_PROHIBIT for `$CFG->defaultuserroleid` in the module context** blocks self-marking and self-revoking (`required_capability_exception`), while the plugin path keeps working. Scoped to the one activity, reversible. Not CSS.
   Side effects (honest): core still **renders the "Mark as done" button** (`cm_completion_details::show_manual_completion()` ignores the capability) – the click fails with a permission error; teachers/managers with `moodle/course:overridecompletion` can still override (identifiable via `overrideby`) – handled as an explicit conflict rule; a manager can remove the role override (detected by verification `users_who_can_toggle()`).
3. **Step 3 – negative.** Automatic tracking cannot be driven: without override, core recomputes the activity's own conditions (requested COMPLETE ignored); override needs `moodle/course:overridecompletion` of the acting user; even with it, an override to INCOMPLETE is undone by the next view trigger (revocation unstable). The 5.0+ hook `after_cm_completion_updated` exists exactly from 5.0 (absent on 4.5) and is dispatched after the write – no veto, no extension point. `mod_lti` has no `FEATURE_COMPLETION_HAS_RULES`.
4. **Step 4.** No direct write to `course_modules_completion` or grade tables in the plugin (static test).

## Decision for the MVP
CS-G4 is **met for mod_lti activities with manual completion tracking + server-side lockdown** (with the UX side effect above), **not met for automatic completion tracking** (the plugin refuses such activities with reason `tracking_automatic` and never claims native completion there). The plugin is built on this basis.

## Update (end of P7 work, same day)
The gate tests passed on all ten combinations 4.5/5.0/5.1/5.2/5.3 × PostgreSQL/MariaDB (PHP 8.3) and the decision was
confirmed end to end by Playwright (learner click refused, native completion and revocation in 4.5↔5.3 and loopback).
See ACCEPTANCE.md.
