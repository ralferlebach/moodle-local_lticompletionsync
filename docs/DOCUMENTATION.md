# local_lticompletionsync – documentation

Spec: `specs/08_lti_completion_sync.md` (German, authoritative). Release 0.2.0 (alpha), version 2026102701. Audit
items of the round 2026-10-27 (CS-01..CS-06, FAM): see ACCEPTANCE.md.

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

**Decision (MVP), advertised as "manual tracking, server-controlled":** CS-G4 is **met for mod_lti activities with
manual completion tracking plus the server-side lockdown** and **not met for automatic completion tracking** (open core
gate, see 2.2). The receiver refuses automatic,
disabled or missing completion tracking with an explicit reason (`unsupported_tracking_automatic`, ...) and never claims
native completion there. Upstream improvement worth proposing to Moodle core: a capability check in
`cm_completion_details::show_manual_completion()` (would remove the visible but useless button).

### 2.1 CS-02: learner UI without a dead end – what core 4.5–5.3 offers (file:line, identical unless noted)

The security measure stays server side (the capability prohibition; both learner entry points check it:
`course/togglecompletion.php:141`, `completion/classes/external.php:84`). What the learner **sees** was examined for every
place where core renders the manual "Mark as done" control (paths: 4.5 tree root / 5.3 `public/`):

| Render path | Where the control is decided | Hook / callback for a plugin? | What the plugin does |
|---|---|---|---|
| Activity page header (all versions) | `lib/classes/output/activity_header.php:190` (4.5) / `:193` (5.3) `if (!$this->hidecompletion)`, data from `course/classes/output/activity_completion.php:79` / `:84` (`show_manual_completion()`) | **Yes:** public API `activity_header::set_hidecompletion()` (`activity_header.php:124`), callable from the hook `\core\hook\output\before_http_headers`, which `core_renderer::header()` dispatches (`lib/classes/output/core_renderer.php:857` / `:911`) **before** the theme layout exports the header (`:888` / `:942`, `render_page_layout()`); `\core\hook\output\after_http_headers` (`:940` / `:994`) adds HTML right below the header | For a tracked learner without the toggle capability on a managed, locked-down activity: header without manual control + explanatory state "Completion: Done (confirmed by the external tool)" / "Not done yet." and why (`role="note"`, template `managed_notice`). Class `local\receiver\learner_view`. |
| Course page (all versions), 5.x activities overview | `course/format/classes/output/local/content/cm/completion.php:74` (4.5) / `:91` (5.3); 5.3 overview `course/format/classes/activityoverviewbase.php:199`; template `core_courseformat/local/content/cm/activity_info.mustache:63` (4.5) / `completion.mustache:63` (5.3) | **No.** Only a course format plugin (own output class) or a theme (template/renderer override) can change it; `cm_completion_details::show_manual_completion()` (`completion/classes/cm_completion_details.php:275`) has no capability check and no callback | Accessible alternative, no hiding: the control stays visible; a note "Set by the external tool" is placed next to it, the button gets `aria-disabled="true"`, `aria-describedby` (the note) and a title; its activation is consumed before core's toggle handler (capture listener), so no error dialog. The control only works with JavaScript anyway (`core_course/manual_completion_toggle`), so without JavaScript it is inert, not a dead end. |
| 5.3 linear navigation footer (activity page) | `course/format/classes/output/local/linearnavigation/footer_content.php:95`, added by `core_courseformat\hook_listener::add_course_navigation_sticky_footer()` (`course/format/classes/hook_listener.php:126`) on `before_footer_html_generation`; ignores `hidecompletion` | **No** (only `moodle_page::set_show_navigation_footer(false)`, `lib/pagelib.php:2621`, which would remove the whole navigation footer – not done) | Same annotation as the course page. Not present in 4.5–5.2. |

Upstream proposal (not part of the product): a capability check (or a module/plugin callback) in
`cm_completion_details::show_manual_completion()` would let core render a read-only state on every path.

### 2.2 Automatic completion condition – open core gate

`mod_lti` declares no `FEATURE_COMPLETION_HAS_RULES` and no `custom_completion` class on 4.5–5.3; a `local` plugin cannot
add a completion rule to another module; `core_completion\hook\after_cm_completion_updated` (5.0+) runs after the write.
An automatic condition therefore needs a core or mod_lti change (upstream) and is **not offered**; the receiver refuses
automatic tracking with `unsupported_tracking_automatic`.

### 2.3 Lockdown and existing role overrides (CS-02)

0.1.0 replaced an explicit override of the authenticated-user role in the activity (e.g. an administrator's CAP_ALLOW)
by its CAP_PROHIBIT and **deleted** it on release (proved by `lockdown_overrides_test::test_v010_algorithm_lost_the_prior_override`).
Since 0.2.0 the lockdown reads the prior override first (`completion_adapter::lockdown()`), stores it
(`local_lticompletionsync_act.priorrecorded/priorpermission`) and the release writes it back exactly
(`completion_adapter::release_lockdown()`); if the prohibition was changed or removed meanwhile, that later decision is
kept (no silent re-lock). Overrides of other roles are never touched. Duplication/restore adopt the copied prohibition
with the source's prior override (backup carries the bookkeeping). Uninstall restores the prior overrides. Teachers keep
`moodle/course:overridecompletion` (handled by the conflict policy); administrators and teachers can switch the
management (and with it the lockdown) off per activity or per connection.

## 3. Other gates

| Gate | Status (see ACCEPTANCE.md for evidence classes) |
|---|---|
| CS-G1 one package, both roles, same site | One plugin; loopback proven by PHPUnit (in-process transport through the real endpoint) and by Playwright on 4.5 and on 5.3 (real HTTP through nginx). |
| CS-G2 4.5 – 5.3 | Static checks, PHPUnit (incl. gate tests and same-site loopback) and Behat executed on 4.5, 5.0, 5.1, 5.2 and 5.3 with PostgreSQL and MariaDB (PHP 8.3); E2E on 4.5 and 5.3. Code free of version assumptions (completion API identical, hook only informational, no `public/` path assumptions). |
| CS-G3 no gradebook effect | Gradebook snapshot equality in gate test, loopback test and E2E (existing grade unchanged, number of grade items unchanged); mutation-checked. Exception, documented: the optional tool-only AGS level (off by default) makes the **platform** create a dedicated progress line item. |
| CS-G4 native completion without fork | See section 2. |
| CS-G5 revocation, replay, late delivery, reconcile | Versioned state machine on both sides; PHPUnit + E2E; mutation-checked. |
| CS-G6 role/capability detection, diagnostics, test matrix | Handshake (`hello`) declares the partner level; capability checks; reduced status view; Moodle events for tool_ltidiagnostics. Matrix 5.0–5.2 and CI open. |

## 3a. Trust boundaries, failure handling and load (CS-03, CS-04)

* **Client ids**: the receiver resolves the requesting tool only from an exact (case-sensitive) client id of exactly one
  configured LTI 1.3 tool type that has a receiver connection here; unknown/foreign → `401 unknown_client`, more than one
  match → `401 ambiguous_client` (core's unique index on `lti_types.clientid` already prevents duplicates), pending or
  rejected types → `401 tool_not_configured`. A message naming another tool's activity → `rejected/wrong_tool`; a
  foreign key → `invalid_signature`; another deployment → `wrong_deployment`.
* **Key rotation**: a rotated tool key that the platform does not know yet fails closed (`http_401:invalid_signature`,
  retry with backoff, nothing applied); repair: the platform administrator updates the tool's key, the tool
  administrator presses "Retry now". A rotated platform key is fetched again once on a miss; when the key set cannot be
  fetched the answer is not trusted (no ack, retry) – "Refresh platform keys" purges the cache.
* **Pseudonym rotation** (ltisource_ltipseudonym): the old pseudonym fails closed (`identity_unresolved`, dead letter);
  the learner's next launch brings the new pseudonym (a new tool account) whose state is delivered normally; requeueing
  the old dead letter fails closed again.
* **Account merges**: different subjects of one local user (merge on the platform) form different version sequences;
  the receiver orders them by the provider time stamp (`subjecthash`), never by comparing foreign version counters.
* **Bounded work**: relation rows in keyset batches of 500, provider states with one query per source and batch,
  existing outbox rows with one query per batch, sender connections and course facts cached per run (30 s TTL), one
  deduplicated drain task instead of one ad hoc task per version for sweep/reconcile/resync, delivery runs bounded by
  rows (setting, default 500) and time (default 120 s), one partner probe per connection and run during an outage,
  lockdown verification with two SQL queries (no `has_capability()` per user). Measured with 10,000 LTI accounts and
  100 resources on PostgreSQL and MariaDB: `tools/load/run.sh`, results in ACCEPTANCE.md (CS-04).

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
  every enabled sender connection. **Force resync** (admin button, CLI `--resync`): the same, with a new version for every
  target even when its state did not change (e.g. after a platform restore).
* **Retry now** (admin button, CLI `--retrynow`): retry rows become due at once (after an outage).
* **Cleanup** (`cleanup_task`, daily): expired replay entries, journal older than the retention.
* CLI `local/lticompletionsync/cli/sync.php --probe|--sweep|--full|--deliver|--status`.

## 7. Identity and pseudonyms

The tool sends back the `sub` that the platform itself sent at launch (`enrol_lti_users.sourceid` of the user and the
published resource, i.e. the binding of that deployment/resource link). The receiver resolves it:

* without pseudonymisation: `sub` must be a local user id (mod_lti default), existing, not deleted, not guest;
* the translator API of `ltisource_ltipseudonym` is detected with `defined('...\\translator::API_VERSION')`: absent
  plugin → plain local ids; installed plugin with an API below 2 (before 0.3.0, no constant counts as 1) → every
  message is refused with `pseudonym_api_unsupported` (permanent, dead letter on the sender; requeue after the upgrade) –
  nothing is guessed;
* with `ltisource_ltipseudonym` (API ≥ 2) installed and a pseudonymising policy for the tool/course/activity: translation only
  through its read-only service (`translator::context_for()` + `user_of()`, `class_exists`-guarded); a retired
  pseudonym (rotation), another scope or tool, a plain id under a pseudonymising policy, or any translator failure →
  `identity_unresolved` (fail closed, dead letter on the sender). No SQL on the other plugin's tables.

Then the user must be tracked for completion in the course (`is_tracked_user`).

## 8. Interoperability levels (spec section 4)

| Level | When | Guarantee |
|---|---|---|
| Cooperative | partner answers `hello` | native completion with signed acknowledgement, revocation, reconcile (manual tracking only) |
| Tool-only (AGS progress) | no partner plugin, `agsprogress` enabled on the sender **and** the platform granted the AGS line items service with the `lineitem` and `score` scopes at launch (the platform is judged by its actual capability set, shown on the connection page) | standard AGS score without `scoreGiven` (`activityProgress`, `gradingProgress=NotReady`) on a **dedicated** line item tagged `lticompletionsync-progress`; the coupled graded line item is never used; no guarantee of native completion, no acknowledgement (`status = sent`); missing AGS scopes → `blocked/ags_not_permitted` |
| No partner | no partner plugin | explicit `blocked/no_partner_plugin`, shown as level "No partner plugin" |
| No partner, no AGS | no partner plugin, `agsprogress` enabled, but the platform granted no line item + score scopes | `blocked/ags_not_permitted`, level "No partner plugin, no AGS" – no promise |
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
The reduced status view (admin page and course page) works without tool_ltidiagnostics, including a **live outcome
report** per connection (successful, revoked, failed and retried transfers with their causes; `status_report::outcomes()`,
admin page and `cli/sync.php --report[=HOURS]` as JSON). One correlation id per version is carried in the signed message,
so the same id appears in the sender's and the receiver's journal and standard log. Journal details are codes; an audit
redaction (`journal::redact()`) removes anything that looks like a JWT, bearer token, PEM key or long opaque token
before it reaches the journal or the standard log.

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

## 11a. Account merges (tool_mergeusers)

Hooks `add_settings_before_merging` and `after_merged_all_tables` (db/hooks.php, typed `object`, no dependency; only
dispatched when tool_mergeusers is installed), same pattern as the family's siblings: the three user tables
(`_out`, `_rcv`, `_log`) are taken out of the generic processing (exceptions keyed by table name; the unique indexes are
declared as compound indexes as well) and merged inside the merge transaction: rows of the merged-away user move to the
kept user; for a target both users have, the newer row survives (outbox: higher version; receiver: later provider time)
and the other is deleted – no duplicate target, no backward version. A failure is reported as a merge error (rollback).

## 11b. Data retention on course, activity, tool and deployment deletion

Core's `remove_course_contents()` deletes the modules of a course without triggering `course_module_deleted` per
module (lib/moodlelib.php, 4.5–5.3); `lti_delete_type()` and the deletion of enrol_lti registrations and deployments
trigger no event at all. The plugin therefore removes its rows on three paths:

* `\core\event\course_content_deleted` (course deletion and "delete contents" restores): receiver states and
  lockdown records of the course's activities, outbox rows whose source is the course, journal rows of the course.
* `\core\event\enrol_instance_deleted` (enrol `lti`, i.e. a published tool): sweep, which removes the tool's outbox
  rows; `course_module_deleted` and `user_deleted` as before.
* `orphan_cleanup::sweep()` in the existing cleanup task (daily): connections whose tool type (receiver) or
  registration/deployment (sender) no longer exists are deleted through `connection::delete()` (with their rows), and
  rows pointing to a missing activity, course, published tool, connection or a deleted user are removed. Each check is
  bounded (`orphan_cleanup::LIMIT` = 5000 rows per run), ids are selected and deleted in chunks – no user data is
  copied or logged, only counts are printed.

The upgrade step to 2026102701 runs the sweep without a bound once, which removes orphans left by 0.1.0/0.2.0 builds.
Lockdown records of a deleted activity need no capability release (the module context is gone with it).

## 12. Uninstall

`db/uninstall.php` releases the learner lockdowns before core drops the tables and writes back the override each
activity had before its lockdown (prohibitions that core copied when a managed activity was duplicated before the
deferred lockdown task ran are removed). Core removes the plugin's own
capabilities, config, tasks and tables; the standard log keeps the plugin's event rows (as for every plugin), and the
cache configuration keeps the `platformkeys` definition until the next upgrade rebuilds it. Evidence:
`tests/uninstall_test.php` and `tools/uninstall/run.sh` (scratch databases, see ACCEPTANCE.md).

## 13. Known limitations

* Only **manual** completion tracking on the platform activity (CS-G4 step 3 negative, open core gate).
* On the course page (and the 5.3 navigation footer) the "Mark as done" button stays visible for locked-down learners
  (no core hook); it is marked unavailable and explained. On the activity page it is replaced by the explanatory state.
* Lockdowns written by 0.1.0 did not remember a prior override; their release removes the prohibition.
* On a Moodle **tool**, learners of an activity-level published resource get their role only in the module context and
  are not "tracked" in the course, so they cannot use a manual toggle there; use automatic completion (e.g. on view) of
  the published activity, or publish the course (course completion).
* `COMPLETE_FAIL` on the tool side is reported as not completed.
* One message per target and version (no batching).
* The tool-only AGS level creates a grade item on the platform (inherent to AGS line items).
* PHP 8.2/8.4 and 5.0–5.2 are run by the lead's matrix; E2E not on 5.0–5.2; CI not executed by us.
