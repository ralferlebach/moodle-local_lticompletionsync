# Changelog

## 0.2.0 (2026-10-28, version 2026102701, alpha)

Build 2026102701 (family integration test, P1 data retention): rows of activities in a deleted course were never
removed (core deletes course modules without `course_module_deleted`), and connections of deleted LTI tool types stayed.
Now: observer for `course_content_deleted` and `enrol_instance_deleted`, bounded orphan sweep in the cleanup task
(missing activity, course, published tool, tool type, registration/deployment, connection, deleted user), upgrade step
2026102701 that removes existing orphans. Tests `orphan_cleanup_test` (real `delete_course()`, `lti_delete_type()`,
enrol instance deletion, bounded sweep, upgrade step, task) and three mutations.

### Build 2026102700 (2026-10-27)

Rework after the family audit of 2026-10-27 (development branch), items CS-01..CS-06. Upgrade step from 0.1.0
(2026102601): three columns and one index, data kept.

* CS-02 – advertised as **manual tracking, server-controlled**; automatic completion condition = open core gate.
  * The learner lockdown remembers an explicit override of the authenticated-user role that existed in the activity
    and restores it exactly on release and uninstall (0.1.0 overwrote it and deleted it on release); a later
    administrator change of the prohibition is kept; duplication/restore adopt the prohibition with the source's prior.
  * Activity page: a locked-down learner sees "Completion: Done / Not done yet – set by the external tool" instead of
    the "Mark as done" button (core API `activity_header::set_hidecompletion()` through the hook `before_http_headers`,
    notice through `after_http_headers`). Course page and 5.3 navigation footer (no core hook): the button stays visible,
    marked `aria-disabled`, described by a note, inert. Core analysis with file:line in DOCUMENTATION.md 2.1.
* CS-03 – foreign, case-variant, ambiguous and inactive client ids are refused with distinct codes; key rotation fails
  closed with administrator repair ("Retry now", "Refresh platform keys"); "Force resync" (new version for every target);
  concurrent, replayed, out-of-order delivery and network interruption tested with real parallel processes on the
  two-site stack.
* CS-04 – keyset batches, one query per source/batch for provider states and existing rows, per-run connection cache,
  one drain task instead of one ad hoc task per version, delivery runs bounded by rows and time, one partner probe per
  run during an outage, lockdown verification in two SQL queries (no per-user capability checks); new index
  `out(sourcekind, sourceid)`; load test tool `tools/load` (10,000 accounts, 100 resources, PostgreSQL + MariaDB).
* CS-05 – grade tables incl. history snapshot-tested on every path; a platform without the partner plugin is judged by
  the AGS capability set it granted (new level "No partner plugin, no AGS"); AGS reference check against a stock
  Moodle AGS endpoint (E2E).
* CS-06 – live outcome report (successful, revoked, failed, retried with causes; admin page and `cli/sync.php --report`),
  audit redaction of credentials in the journal and the standard log.
* Account merges: tool_mergeusers hooks (`add_settings_before_merging`, `after_merged_all_tables`); outbox, receiver states
  and journal follow the kept user without duplicates; receiver orders joined subject sequences by provider time
  (`rcv.subjecthash`).
* ltisource_ltipseudonym: translator `API_VERSION >= 2` required (checked with `defined()`); an older installed plugin
  fails closed with `pseudonym_api_unsupported`, an absent one degrades to local user ids.
* New settings: rows and time per delivery run. New CLI options `--resync`, `--retrynow`, `--report`.

## 0.1.0 (2026-10-26, version 2026102601, alpha)

First release (new member of the LTI plugin family, spec 08).

* CS-G4 gate evidence on Moodle 4.5 and 5.3 (`tests/gates`): native completion of manual-tracking mod_lti activities
  through `completion_info::update_state()`, learner lockdown by capability prohibition, automatic tracking refused.
* Both roles in one plugin, chosen per connection: sender (enrol_lti published resources) and receiver (mod_lti
  activities); connection register with administrative confirmation.
* Signed channel (documented LTI extension "lticompletionsync v1") reusing the enrol_lti tool key and the mod_lti platform
  key; replay protection, audience/deployment checks, signed acknowledgement; SSRF guard with DNS pinning.
* Versioned state machines on both sides (received/validated/applied/rejected/conflict), outbox with ad hoc delivery,
  delta sweep, full reconcile, exponential backoff, dead letter, revocation, idempotency, out-of-order handling, locks.
* Optional tool-only AGS progress level on a dedicated line item; explicit "no partner plugin" declaration.
* ltisource_ltipseudonym integration through its read-only translator (fail closed on rotation).
* Standard events of spec section 7 with correlation ids, journal and reduced status pages (admin, course, activity).
* Privacy API, capabilities, backup/restore of the per-activity setting, en + de.
* Uninstall removes the learner lockdowns (`db/uninstall.php`); checked on scratch databases (4.5/5.3 × PG/MariaDB).
* Receiver serialises parallel messages per target (lock, `received/busy` answer); a platform without the plugin is
  probed again after one day.
* E2E: pseudonymous subjects with ltisource_ltipseudonym incl. rotation (fail closed).
* CS-G6: met with tool_ltidiagnostics >= 2026102600 (transfers with correlation id in its timeline/evidence on both
  sites, E2E-proved); without it, the plugin's own reduced status page.
* Version note: 2026102600 was used for the first gate runs (schema only); 2026102601 registers capabilities, tasks,
  events and the cache definition. No upgrade step is needed (fresh installs get 2026102601 directly).
