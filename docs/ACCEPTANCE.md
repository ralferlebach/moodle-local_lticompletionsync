# Acceptance – local_lticompletionsync 0.1.0 (version 2026102601)

Status classes (COMMON brief): **CP** code present / **TL** automated tests executed locally / **CI** CI passed (not
executed) / **FA** functionally accepted (by the owner; nothing is FA yet) / **NV** not verified.
"TL" names the evidence test; the runner table below says on which tree/database combinations it ran.
Date of all runs: 2026-10-26, final code (version 2026102601 unchanged). PHP 8.3 on all runs; the PHP 8.2/8.3/8.4 matrix is run by the lead with `ltifullmatrix`.

## Runner results

`ltirun <tree> <db> local_lticompletionsync all` = phplint, validate, savepoints, mustache, codechecker (0 warnings),
phpdoc (0 warnings), PHPUnit (`--fail-on-warning --fail-on-risky --fail-on-skipped`).

| Tree | DB | Static checks (6) | PHPUnit | Behat (non-JS) |
|---|---|---|---|---|
| Moodle 4.5 | PostgreSQL 16 | all OK | OK, 94 tests, 409 assertions | 6 scenarios / 72 steps passed |
| Moodle 4.5 | MariaDB 11.4 | all OK | OK, 94 tests, 409 assertions | 6 scenarios / 72 steps passed |
| Moodle 5.0 | PostgreSQL 16 | all OK | OK, 94 tests, 409 assertions (15 accepted deprecations) | 6 scenarios / 72 steps passed |
| Moodle 5.0 | MariaDB 11.4 | all OK | OK, 94 tests, 409 assertions (15 accepted deprecations) | 6 scenarios / 72 steps passed |
| Moodle 5.1 | PostgreSQL 16 | all OK | OK, 94 tests, 409 assertions (15 accepted deprecations) | 6 scenarios / 72 steps passed |
| Moodle 5.1 | MariaDB 11.4 | all OK | OK, 94 tests, 409 assertions (15 accepted deprecations) | 6 scenarios / 72 steps passed |
| Moodle 5.2 | PostgreSQL 16 | all OK | OK, 94 tests, 409 assertions (15 accepted deprecations) | 6 scenarios / 72 steps passed |
| Moodle 5.2 | MariaDB 11.4 | all OK | OK, 94 tests, 409 assertions (15 accepted deprecations) | 6 scenarios / 72 steps passed |
| Moodle 5.3 | PostgreSQL 17 | all OK | OK, 94 tests, 412 assertions (15 accepted deprecations) | 6 scenarios / 72 steps passed |
| Moodle 5.3 | MariaDB 11.4 | all OK | OK, 94 tests, 412 assertions (15 accepted deprecations) | 6 scenarios / 72 steps passed |

PHPUnit includes the 14 CS-G4 gate tests and the same-site loopback integration (both roles) on every row, so the
gate evidence and a provider/consumer smoke exist for all five versions and both databases.

* PHPUnit deprecations on 5.x are the accepted docblock-annotation deprecations of PHPUnit 11 (COMMON rule).
* Upgrade: the real upgrade 2026102600 → 2026102601 ran on 4.5 and 5.3 (PostgreSQL and MariaDB); 5.0/5.1/5.2 were
  fresh installs of 2026102601.
* Playwright (two-site stack, Chromium, A = 4.5/PostgreSQL, B = 5.3/PostgreSQL, tool_ltidiagnostics 0.2.1 on both): **19/19 passed** (4 directions × 4 tests +
  pseudonym/diagnostics scenario 3 tests); the ltisource_ltipseudonym policy table on A is empty again afterwards.
* Uninstall on scratch databases (`tools/uninstall/run.sh`, 4.5 and 5.3 × PostgreSQL and MariaDB, seeded with both roles,
  two lockdowns, outbox/received/replay/journal rows, ad hoc tasks, config, cache): after `uninstall_plugins.php --run`
  **no plugin table, config, capability, role capability, scheduled or ad hoc task, file, user preference or observer
  left; 0 `togglecompletion` overrides in the managed activities; the learner can mark both activities again.** Left by
  core by design: the plugin's rows in `logstore_standard_log` (log history) and the `platformkeys` entry in the cache
  configuration (rebuilt at the next upgrade).
* Mutation checks (`tools/mutation_check.py`, 5.3/PostgreSQL): **7/7 killed**.
* CI workflows: validated locally (family workflow checker: 97 checks, 0 errors); CI **not executed**.
* Behat `@javascript`: no such scenario (the JS button is covered by Playwright).

## Gates (spec section 9)

| Gate | Status | Evidence | Gaps |
|---|---|---|---|
| CS-G1 one package, sender and receiver also on the same site | **TL** | `loopback_flow_test` (18, both roles on one site through the real endpoint); Playwright scenarios `la` (4.5) and `lb` (5.3) through nginx | – |
| CS-G2 4.5 to 5.3 statically confirmed and functionally tested per version | **TL**: all five versions × PostgreSQL/MariaDB (static checks, PHPUnit incl. gates and loopback, Behat); E2E on 4.5 and 5.3 | runner table | PHP 8.2/8.4 by the lead's matrix; E2E not on 5.0–5.2; CI not executed |
| CS-G3 no unintended gradebook effect | **TL**, mutation-checked | `cs_g4_gate_test::test_step1_manual_set_and_revoke_without_grade_operations` (grades, history, items byte-identical), `loopback_flow_test::test_completion_arrives_natively_without_grade_effect` (existing grade 77), `test_revocation_is_stable_after_cron` (no grade created), E2E (existing grade and grade item count unchanged, 4 scenarios), `test_step4_no_direct_completion_table_writes_in_plugin` | the optional tool-only AGS level (off by default) creates a dedicated progress line item on the platform by design |
| CS-G4 native mod_lti completion without core fork and without uncontrollable learner completion | **TL – met for manual completion tracking + server-side lockdown; not met for automatic tracking** (refused explicitly) | `tests/gates/cs_g4_gate_test.php` (14), E2E "learner cannot toggle it" (click refused, state unchanged), Behat lockdown scenario; mutation "CAP_PREVENT instead of CAP_PROHIBIT" killed | UX: core still shows the "Mark as done" button to locked-down learners (click fails); teachers with override capability can override (handled by conflict policy) |
| CS-G5 revocation, replay protection, late delivery, reconciliation | **TL**, mutation-checked | `loopback_flow_test`: `test_revocation_is_stable_after_cron`, `test_replay_is_refused`, `test_lost_answer_retry_is_idempotent`, `test_out_of_order_old_version_is_rejected`, `test_backoff_and_dead_letter`, `test_course_resource_reports_course_completion_and_revocation` (sweep finds a revocation without an event); E2E revocation after cron + reload, duplicate/out-of-order | – |
| CS-G6 role/capability detection, diagnostics integration, test matrix | **TL – met with tool_ltidiagnostics >= 2026102600 (0.2.1); without it, own reduced status page** | handshake and levels (`test_no_partner_plugin_is_declared`, `test_authentication_negatives` → partner "disabled", E2E admin levels "Receiver"/"Cooperative"); events with correlation id in the standard log of both sites (`plugin_contract_test::test_events`); E2E `pseudonym_diagnostics.spec.js` test 3: the correlation id produced by the transfer appears in tool_ltidiagnostics' timeline/evidence for the tool connection on B (plus the delivery task runs) and for the platform connection on A; reduced status pages without tool_ltidiagnostics (Behat, E2E) | with tool_ltidiagnostics < 2026102600 (0.2.0) the events are not read (only the task runs); PHP 8.2/8.4 by the lead's matrix; CI not executed |

## DoD rows (spec section 8)

| Test case | Status | Evidence |
|---|---|---|
| 4.5→4.5 and 5.3→5.3: completion without grade creates **native** mod_lti completion | **TL** | E2E `la`, `lb`; PHPUnit loopback on every executed tree |
| 4.5→5.3 and 5.3→4.5: identical behaviour in mixed versions | **TL** | E2E `ab` (A 4.5 tool → B 5.3 platform, second registration), `ba` (B 5.3 tool → A 4.5 platform, stack registration) |
| 5.0, 5.1, 5.2: provider/consumer smoke with database matrix | **TL** (PHPUnit + Behat) | same-site loopback (both roles, real endpoint, real enrol_lti/auth_lti flows), gate tests and the 6 Behat scenarios on PostgreSQL and MariaDB; browser E2E on these versions **NV** |
| No grade / existing grade: neither score nor gradebook value changes | **TL** | see CS-G3 |
| Revocation: INCOMPLETE stays after cron and reload | **TL** | `test_revocation_is_stable_after_cron`, `cs_g4_gate_test::test_step1_revocation_survives_cron_and_cache_purge`, E2E test 2 of each scenario |
| Lost answer, duplicate send, out-of-order: no double or backward booking, defined reconciliation | **TL** | `test_lost_answer_retry_is_idempotent` (one completion event only), `test_out_of_order_old_version_is_rejected`, `state_test::test_outbox_superseded_result_is_dropped`, `test_parallel_delivery_is_locked`, `test_receiver_target_is_locked`, E2E test 3 |
| No AGS permission: understandable capability check instead of false success | **TL** | `ags_progress_test::test_missing_ags_permission_is_reported` (`blocked/ags_not_permitted`), tool-only level `test_progress_on_dedicated_line_item` (graded line item never used, no `scoreGiven`) |
| No partner plugin: explicit declaration of reduced capabilities | **TL** | `test_no_partner_plugin_is_declared` (level "No partner plugin", `blocked/no_partner_plugin`), levels in the admin register |
| Scoped pseudonyms / rotation: correct identity or documented fail-closed state | **TL** (PHPUnit with the real ltisource_ltipseudonym; E2E on the stack) | `test_pseudonym_integration_and_rotation`, `test_unresolvable_identity_fails_closed`; E2E `pseudonym_diagnostics.spec.js`: pseudonymous `sub` on the tool, resolved to the real learner on A (native completion, journal `identity_resolved=pseudonym`, second learner untouched); after rotation: B dead letter `identity_unresolved` visible in B's journal page, A unchanged for all learners, `service_pseudonym_miss` audit row and `rejected:identity_unresolved` journal row on A |
| Privacy / permissions / SSRF: automatic negative tests | **TL** | `privacy/provider_test` (contexts, export, deletion, user deletion), `url_guard_test` (15 refused URLs, DNS rebinding), `state_test::test_connection_validation`, `test_authentication_negatives`, `navigation_test`, Behat (learner sees no management page, sender validation) |
| Tests / CI: PHPUnit, Behat, UI-E2E, upgrade, restore/backup, parallel tasks; no skips counted as green | **TL** except CI | PHPUnit (no skips, `--fail-on-skipped`), Behat non-JS, Playwright, real upgrade run, `backup_restore_test` (duplicate, backup + restore into another course with deferred lockdown), `test_parallel_delivery_is_locked`; **CI not executed**; uninstall: `uninstall_test` + scratch-DB check (see above) |

## Other acceptance items

| Item | Status | Evidence |
|---|---|---|
| Authenticated endpoint reusing the LTI 1.3 trust (no fake AGS endpoint) | TL | `test_authentication_negatives`, `keys_test`, E2E (real JWKS fetches over TLS in all four scenarios) |
| Signed end-to-end acknowledgement (2xx alone is no confirmation) | TL | dispatcher only acks `applied ≥ version` (`test_lost_answer_retry_is_idempotent`, E2E) |
| Outbox, ad hoc, delta sweep, full reconcile, backoff, dead letter | TL | `loopback_flow_test`, `state_test`, E2E `cron` stage |
| Correlation id and the 11 events of spec section 7 | TL | `plugin_contract_test::test_events`, `test_completion_arrives_natively_without_grade_effect` (event trail) |
| Reduced status view without tool_ltidiagnostics | TL | Behat (admin register, course status page), E2E (journal) |
| Backup/restore of the per-activity setting | TL | `backup_restore_test` |
| Conflict rules for local overrides | TL | `test_teacher_override_is_reasserted_with_provider_policy`, `test_teacher_override_is_kept_with_local_policy` |
| Admin + teacher UI (en + de) | TL | Behat (en); `plugin_contract_test::test_language_parity` (de keys); German UI not browsed (NV) |
| Accessibility check of the pages | NV | – |
