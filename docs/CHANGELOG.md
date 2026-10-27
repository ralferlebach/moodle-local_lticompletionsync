# Changelog

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
