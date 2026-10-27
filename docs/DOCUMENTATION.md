# local_lticompletionsync – documentation

Spec: `specs/08_lti_completion_sync.md` (German, authoritative). Release 0.1.0 (alpha), version 2026102601.

## 1. What the plugin does

One installable plugin (CS-G1) that transfers **activity or course completion** of a resource published with
*Publish as LTI tool* (enrol_lti, the **tool / provider** side) to the **same mod_lti activity** on the LTI 1.3
**platform / consumer** side, as **native** Moodle activity completion, with revocation, versioned state, signed
end-to-end acknowledgement, reconciliation and **without any grade operation** (CS-G3).

The role is decided **per connection**: the same installation can be sender for one platform, receiver for one tool and
both for itself (same-site loopback).

```
 tool site (sender)                                         platform site (receiver)
 ───────────────────                                        ────────────────────────
 completion event / sweep / reconcile                       /local/lticompletionsync/endpoint.php
   └─ source: enrol_lti user ─ resource link ─ connection     ├─ verify JWS with mod_lti trust of the tool type
   └─ outbox row: desired state, version, statetime            ├─ aud / iss=sub=client id / deployment / exp / jti
   └─ ad hoc dispatch (lock) ──── signed JWS (tool key) ──────►├─ resolve lti instance (resource_link_id), cm, user
                                                              │   (sub = user id, or pseudonym via ltisource_ltipseudonym)
   ◄──────────── signed answer (platform key) ────────────────┤├─ versioned state row: received→validated→applied/…
   └─ acked / retry (backoff) / dead letter / blocked         └─ completion_info::update_state() (manual tracking)
```

## 2. Machbarkeitsgate CS-G4 (spec section 5) – result and evidence

Evidence: `tests/gates/cs_g4_gate_test.php` (14 tests), executed on Moodle 4.5, 5.0, 5.1, 5.2 and 5.3 with PostgreSQL and MariaDB
(see ACCEPTANCE.md). Interim note: `docs/GATE_CS-G4_INTERIM.md`.

| Step | Question | Finding on 4.5 and 5.3 | Evidence test |
|---|---|---|---|
| 1 | Set and revoke native completion of a **manual-tracking** mod_lti activity for one user from server code, without grade operations | **Yes.** `completion_info::update_state($cm, COMPLETE/INCOMPLETE, $userid)` without override needs no capability of the acting user (run with no user), fires `course_module_completion_updated`, is idempotent; `grade_grades`, `grade_grades_history`, `grade_items` unchanged; revocation survives cache purge and `completion_regular_task`; grade changes never touch manual completion (`inform_grade_changed()` returns for manual tracking). | `test_step1_manual_set_and_revoke_without_grade_operations`, `test_step1_revocation_survives_cron_and_cache_purge`, `test_step1_grade_change_does_not_touch_manual_completion` |
| 2 | Prevent learner self-toggling, or treat it as non-authoritative, **without CSS** | **Yes, server side, with a UX side effect.** Both learner entry points (`core_completion_external::update_activity_completion_status_manually`, `course/togglecompletion.php`) call `require_capability('moodle/course:togglecompletion', module context)` before `update_state()` (static check of the tree's sources). The capability's only archetype is the authenticated-user role, so `CAP_PREVENT` for `student` is **not** enough (proved); `CAP_PROHIBIT` for `$CFG->defaultuserroleid` in the module context blocks marking and revoking (`required_capability_exception`), only for that activity, reversibly, while the plugin path keeps working. **Side effects:** core still renders the "Mark as done" button (`cm_completion_details::show_manual_completion()` ignores the capability; the click fails with a permission error); teachers with `moodle/course:overridecompletion` can still override (recognisable by `overrideby`, handled by the conflict policy); a manager can remove the role override (shown as "users who can still mark it themselves"). Site admins bypass capability checks (not tracked). | `test_step2_learner_can_toggle_without_lockdown` (control), `test_step2_prevent_for_student_role_is_not_enough`, `test_step2_lockdown_blocks_learner_toggle_but_not_the_adapter`, `test_step2_all_learner_toggle_paths_check_the_capability`, `test_step2_side_effect_button_still_rendered`, `test_step2_teacher_override_is_possible_and_identifiable` |
| 3 | Automatic completion configuration incl. display and revocation via official APIs | **No.** Without override core recomputes the activity's own conditions (a requested COMPLETE is ignored); `override=true` needs `moodle/course:overridecompletion` of the acting user; even with it an override to INCOMPLETE is undone by the next view trigger (revocation not stable). `mod_lti` has no `FEATURE_COMPLETION_HAS_RULES`; the hook `core_completion\hook\after_cm_completion_updated` exists exactly from 5.0 (absent on 4.5) and is dispatched after the write (no veto). | `test_mod_lti_has_no_completion_rules`, `test_step3_automatic_tracking_is_not_externally_controllable`, `test_step3_override_revocation_is_not_stable_under_automatic_tracking`, `test_step3_completion_hook_presence_matches_version` |
| 4 | No hidden DB writes | No write to `course_modules_completion` or grade tables anywhere in the plugin (static test over the plugin sources). | `test_step4_no_direct_completion_table_writes_in_plugin` |

**Decision (MVP):** CS-G4 is **met for mod_lti activities with manual completion tracking plus the server-side lockdown**
(with the documented UX side effect) and **not met for automatic completion tracking**. The receiver refuses automatic,
disabled or missing completion tracking with an explicit reason (`unsupported_tracking_automatic`, ...) and never claims
native completion there. Upstream improvement worth proposing to Moodle core: a capability check in
`cm_completion_details::show_manual_completion()` (would remove the visible but useless button).

## 3. Other gates

| Gate | Status (see ACCEPTANCE.md for evidence classes) |
|---|---|
| CS-G1 one package, both roles, same site | One plugin; loopback proven by PHPUnit (in-process transport through the real endpoint) and by Playwright on 4.5 and on 5.3 (real HTTP through nginx). |
| CS-G2 4.5 – 5.3 | Static checks, PHPUnit (incl. gate tests and same-site loopback) and Behat executed on 4.5, 5.0, 5.1, 5.2 and 5.3 with PostgreSQL and MariaDB (PHP 8.3); E2E on 4.5 and 5.3. Code free of version assumptions (completion API identical, hook only informational, no `public/` path assumptions). |
| CS-G3 no gradebook effect | Gradebook snapshot equality in gate test, loopback test and E2E (existing grade unchanged, number of grade items unchanged); mutation-checked. Exception, documented: the optional tool-only AGS level (off by default) makes the **platform** create a dedicated progress line item. |
| CS-G4 native completion without fork | See section 2. |
| CS-G5 revocation, replay, late delivery, reconcile | Versioned state machine on both sides; PHPUnit + E2E; mutation-checked. |
| CS-G6 role/capability detection, diagnostics, test matrix | Handshake (`hello`) declares the partner level; capability checks; reduced status view; Moodle events for tool_ltidiagnostics. Matrix 5.0–5.2 and CI open. |

## 4. Protocol "lticompletionsync v1" (documented LTI extension, not AGS)

* `POST {platform}/local/lticompletionsync/endpoint.php`, body = compact JWS, `Content-Type: application/jwt`, max 16 KiB.
* **Request JWS**: `alg=RS256`, `kid` and key = the enrol_lti tool key of the sender (`enrol_lti/lti_13_privatekey`,
  served at `/enrol/lti/jwks.php`), i.e. the key the platform already registered for the LTI 1.3 tool type.
  Claims: `iss = sub = client id` (of the tool at the platform), `aud = endpoint URL`, `iat`, `exp` (≤ message TTL),
  `jti` (16–128 chars, random), and `lticompletionsync`:
  `{v:1, type: hello|completion|status, deployment_id, resource_link_id, user, state: completed|incomplete,
  activity_progress, version, timestamp, correlation_id, source: cm|course}`.
* **Platform verification**: receiver connection found by client id (LTI 1.3 type); `403 connection_not_enabled` if not
  enabled; signature via mod_lti's own `lti_verify_jwt_signature()` (public key or key set of the type, with
  clock-skew leeway); `aud`, `sub`, `iat`/`exp`/TTL, `deployment_id == type id` (mod_lti's deployment id), unique `jti`
  (unique index on sha256(connection|jti), kept until expiry) → `401/400/409` unsigned JSON errors.
* **Answer**: `200 application/jwt`, signed with the mod_lti platform key (`jwks_helper`, `/mod/lti/certs.php`),
  `iss = platform wwwroot`, `aud = client id`, claim `lticompletionsync = {status, reason, version, appliedversion,
  state, in_reply_to: request jti}`. The sender verifies it with the platform key set registered in enrol_lti
  (`enrol_lti_app_registration.jwksurl`, cached, refetched once on a key miss) and checks `iss`, `aud`, `in_reply_to`.
* A `2xx` alone is never a confirmation: only `status = applied` with `appliedversion ≥ version` is.
* `hello` returns plugin, release, protocol, levels, `native_completion: manual_tracking_only`, lockdown and conflict
  policy. `status` returns the stored state of a target.

## 5. State machines

**Sender (tool) – `local_lticompletionsync_out`**, one row per (connection, resource link, user):
`desiredstate`, monotonic `version`, strictly increasing `statetime`, `correlationid` per version,
`status`: `pending → acked | retry → … → dead | blocked | sent(tool-only)`. A revocation is a new version with state 0,
never a deletion. A target that never reported "completed" creates no row for "incomplete". Writes of a delivery result
are conditional on the version (optimistic concurrency): a result for an older version never overwrites a newer one.
Deliveries take a lock per row (parallel tasks). Backoff: `backoffbase · 2^(attempt-1)`, capped by `backoffmax`, +0–10 %
jitter; after `maxattempts` → `dead` (dead letter). Permanent refusals (`PERMANENT_REASONS`, e.g. `identity_unresolved`,
`activity_not_managed`, `unsupported_tracking_automatic`, `stale_version` without a newer applied version) → `dead`;
`conflict` → `blocked`; no partner plugin → `blocked` (`no_partner_plugin`) or tool-only AGS level; a platform without
the plugin is checked again at the first delivery after one day (or at once with "Check partner"); blocked and dead rows
are sent again with "Requeue dead letters" (same version, answered idempotently).

**Receiver (platform) – `local_lticompletionsync_rcv`**, one row per (connection, activity, user):
`received → validated → applied | rejected | conflict`; `remoteversion`, `remotestate`, `appliedversion`.
Older version → `rejected/stale_version` (no change); same version again → stored answer (no second application, no
second event); newer version → applied through the adapter. Conflict policy per receiver connection: `provider`
(a local change of a managed activity – e.g. a teacher override – is reverted by the ad hoc `reassert_task`) or `local`
(the local change wins and is reported as `conflict/local_override`).

Mapping (spec section 6): AGS `Initialized/Started/InProgress/Submitted` → not completed, `Completed` → completed.
Tool side states: activity `COMPLETION_COMPLETE` and `COMPLETE_PASS` → completed (`COMPLETE_FAIL` → not completed);
course: stored `course_completions.timecompleted` (never guessed from single activities).

## 6. Delivery paths

* **Event path**: observers of `course_module_completion_updated` and `course_completed` record a new version and queue a
  deduplicated ad hoc `dispatch_task` (no network in observers).
* **Delta sweep** (`sweep_task`, every 5 minutes): re-reads the provider state of every reported target (catches
  revocations without an event, e.g. deleted course completions after a reset) and of completions changed since the last
  run, then delivers due retries.
* **Full reconcile** (`full_reconcile_task`, daily; admin button; CLI `--full`): every user of every resource link of
  every enabled sender connection.
* **Cleanup** (`cleanup_task`, daily): expired replay entries, journal older than the retention.
* CLI `local/lticompletionsync/cli/sync.php --probe|--sweep|--full|--deliver|--status`.

## 7. Identity and pseudonyms

The tool sends back the `sub` that the platform itself sent at launch (`enrol_lti_users.sourceid` of the user and the
published resource, i.e. the binding of that deployment/resource link). The receiver resolves it:

* without pseudonymisation: `sub` must be a local user id (mod_lti default), existing, not deleted, not guest;
* with `ltisource_ltipseudonym` installed and a pseudonymising policy for the tool/course/activity: translation only
  through its read-only service (`translator::context_for()` + `user_of()`, `class_exists`-guarded); a retired
  pseudonym (rotation), another scope or tool, a plain id under a pseudonymising policy, or any translator failure →
  `identity_unresolved` (fail closed, dead letter on the sender). No SQL on the other plugin's tables.

Then the user must be tracked for completion in the course (`is_tracked_user`).

## 8. Interoperability levels (spec section 4)

| Level | When | Guarantee |
|---|---|---|
| Cooperative | partner answers `hello` | native completion with signed acknowledgement, revocation, reconcile (manual tracking only) |
| Tool-only (AGS progress) | no partner plugin and `agsprogress` enabled on the sender | standard AGS score without `scoreGiven` (`activityProgress`, `gradingProgress=NotReady`) on a **dedicated** line item tagged `lticompletionsync-progress`; the coupled graded line item is never used; no guarantee of native completion, no acknowledgement (`status = sent`); missing AGS scopes → `blocked/ags_not_permitted` |
| No partner | no partner plugin | explicit `blocked/no_partner_plugin`, shown as level "No partner plugin" |
| Platform-only | platform with plugin, tool without | nothing can arrive (a missing sender cannot be replaced) – declared by the receiver level only |

## 9. Configuration

*Site administration > Plugins > Local plugins > LTI completion sync*:

* **Completion sync connections** (`local/lticompletionsync:manageconnections`): register, enable/disable (administrative
  confirmation on both sites), check partner, full reconcile, requeue dead letters, environment facts, transfer journal
  (filter by correlation id).
  * Sender: enrol_lti deployment, endpoint URL (https, host of the registered issuer, SSRF guard), tool-only AGS option.
  * Receiver: LTI 1.3 tool type, automatic management of all manual-tracking activities of the tool, learner lockdown,
    conflict policy.
* **Settings**: allow private targets (test labs only), delivery attempts, first/maximum retry delay, message TTL,
  clock skew, journal retention.
* Teachers (`local/lticompletionsync:manageactivity`): *External tool activity > More > Completion sync* – switch
  provider-managed completion on/off (writes/removes the lockdown), see lockdown state and received states.
* Course status (`local/lticompletionsync:viewstatus`): *Course > More > Completion sync status* – outgoing and incoming
  states and the course journal.

## 10. Events (spec section 7) and diagnostics

Every transfer has a `correlation_id` (UUID, per version, sent to the receiver). Standard events, recorded in the
plugin's journal (`local_lticompletionsync_log`) **and** triggered as Moodle events for log stores and tool_ltidiagnostics
(event class in brackets, Moodle requires `object_action` names): `source_detected`, `queued` (`message_queued`), `sent`
(`message_sent`), `http_received`, `identity_resolved`, `completion_applied`, `completion_revoked`, `acknowledged`
(`message_acknowledged`), `retry_scheduled`, `dead_letter`, `reconciled` (`state_reconciled`). `other` holds
correlation id, connection id, version and a short code – never a JWT, token, key or payload.
The reduced status view (admin page and course page) works without tool_ltidiagnostics.

**Integration with tool_ltidiagnostics (CS-G6):** met with tool_ltidiagnostics >= 2026102600 (0.2.1). It reads these
events through the family prefix in the log store and an observer on `\local_lticompletionsync\event\sync_event`,
correlates them by `other['correlationid']` and shows the transfers (with the delivery task runs) in the timeline and
evidence of the connection on both sites (proved on the stack, `tests/playwright/pseudonym_diagnostics.spec.js`).
Without it – or with 0.2.0, which reads only a fixed core/mod_lti event list and the task runs – the plugin's own reduced
status pages are the diagnosis tool.

## 11. Backup and restore

The per-activity setting is backed up with the activity (`backup_local_lticompletionsync_plugin`, module level). On
restore and on duplication the lockdown is written by an ad hoc `lockdown_task` after the restore (core's
`duplicate_module()` / 5.2+ `cmactions::duplicate()` copy the permission overrides blindly after the restore; writing the
override during the restore made duplication fail with a duplicate key – found and fixed by
`backup_restore_test::test_duplicate_keeps_management`). Transfer states are not backed up (the provider re-sends).

## 12. Uninstall

`db/uninstall.php` removes the learner lockdowns before core drops the tables: every CAP_PROHIBIT of
`moodle/course:togglecompletion` for the authenticated-user role in a managed activity (also prohibitions that core
copied when a managed activity was duplicated before the deferred lockdown task ran). Core removes the plugin's own
capabilities, config, tasks and tables; the standard log keeps the plugin's event rows (as for every plugin), and the
cache configuration keeps the `platformkeys` definition until the next upgrade rebuilds it. Evidence:
`tests/uninstall_test.php` and `tools/uninstall/run.sh` (scratch databases, see ACCEPTANCE.md).

## 13. Known limitations

* Only **manual** completion tracking on the platform activity (CS-G4 step 3 negative).
* The "Mark as done" button stays visible for locked-down learners (core renders it regardless of the capability).
* On a Moodle **tool**, learners of an activity-level published resource get their role only in the module context and
  are not "tracked" in the course, so they cannot use a manual toggle there; use automatic completion (e.g. on view) of
  the published activity, or publish the course (course completion).
* `COMPLETE_FAIL` on the tool side is reported as not completed.
* One message per target and version (no batching).
* The tool-only AGS level creates a grade item on the platform (inherent to AGS line items).
* PHP 8.2/8.4 not executed by this agent (lead's matrix); E2E not on 5.0–5.2; CI not executed.
