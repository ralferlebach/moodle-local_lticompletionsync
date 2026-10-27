# Testing

PHPUnit keeps the PHPUnit 9 style (docblock `@covers` / `@dataProvider`, no attributes); on Moodle 5.x (PHPUnit 11)
the resulting deprecations are accepted. Suite: `local_lticompletionsync_testsuite`. Skipped tests count as failures
(`--fail-on-skipped`): no test is skipped on any tree – tests that depend on an optional plugin
(ltisource_ltipseudonym) assert the fail-closed behaviour when it is absent and the translation when it is present.

## Layers

| Layer | Files | What it proves |
|---|---|---|
| Gate evidence (PHPUnit, real core) | `tests/gates/cs_g4_gate_test.php` (14) | CS-G4 steps 1–4 on the running Moodle version (see DOCUMENTATION.md section 2) |
| Unit | `tests/local/protocol_test.php`, `tests/local/net/url_guard_test.php`, `tests/local/keys_test.php`, `tests/local/state_test.php`, `tests/plugin_contract_test.php` | mappings, backoff, SSRF guard incl. DNS pinning with a rebinding resolver, JWS sign/verify/tamper, outbox versions and optimistic concurrency, message validation, connection validation, install/upgrade, capabilities, the 11 events, language parity, tasks |
| Integration (real core flows) | `tests/integration/loopback_flow_test.php` (18), `tests/integration/ags_progress_test.php`, `tests/privacy/provider_test.php`, `tests/backup_restore_test.php`, `tests/navigation_test.php` | same-site loopback (CS-G1) through real auth_lti account creation, real enrol_lti `tool_launch_service`, real completion API/events on the tool side, the real endpoint with mod_lti's `lti_verify_jwt_signature()` and the completion API on the platform side; only the launch JWT itself is mocked (as in core's enrol_lti tests) and HTTP is replaced by an in-process transport (`tests/fixtures/loopback_http_client.php`) |
| Behat (non-JS) | `tests/behat/lticompletionsync.feature` (6 scenarios), generator `tests/generator` | admin register (empty state, environment, add/confirm/disable receiver, sender validation), teacher opt-in with lockdown verification, automatic tracking refused, learner without access, course status page |
| Playwright E2E | `tests/playwright/lticompletionsync.spec.js` (16 tests), `tests/playwright/pseudonym_diagnostics.spec.js` (3 tests), seed extension `tests/playwright/fixtures/csync_seed.php` | B(5.3)→A(4.5) with the stack registration, A(4.5)→B(5.3) with a second registration, loopback on 4.5 and on 5.3: native completion, grade untouched, learner toggle refused, revocation after cron + reload, duplicate/out-of-order, admin levels and journal; pseudonymous `sub` (ltisource_ltipseudonym policy on the scenario's own type, removed afterwards) resolved to the real learner, rotation fails closed with visible reason and audit; what tool_ltidiagnostics shows (see `tests/playwright/README.md`) |
| Uninstall | `tests/uninstall_test.php`, `tools/uninstall/run.sh` (+ `seed.php`, `leftovers.php`) | lockdown overrides removed by `db/uninstall.php`; on scratch databases (`LTI_DBNAME=csync_unin_<tree>`, `LTI_BASE=/home/claude/data/csync_unin_<tree>_<db>`, runner lock held, dropped afterwards): install, seed both roles, `uninstall_plugins.php --run`, list leftovers |
| Mutation checks | `tools/mutation_check.py` | 7 mutations of key guarantees (grade untouched, revocation, replay, out-of-order, pseudonym fail closed, lockdown, audience) must turn tests red |

## Commands

```bash
# Static checks + PHPUnit (tree 45|50|51|52|53, db pgsql|mariadb)
/home/claude/bin/ltirun 45 pgsql local_lticompletionsync all
/home/claude/bin/ltirun 53 pgsql local_lticompletionsync phpunit --filter cs_g4_gate_test   # gate evidence only

# Behat non-JS (after a mirror)
/home/claude/bin/ltirun 45 pgsql local_lticompletionsync mirror
/home/claude/bin/ltibehat 45 pgsql local_lticompletionsync

# Playwright on the two-site stack (plugin mirrored and upgraded on 45/pgsql and 53/pgsql)
/home/claude/e2e/bin/e2e-up
NODE_PATH=/home/claude/e2e/node_modules /home/claude/e2e/node_modules/.bin/playwright test \
    -c tests/playwright/playwright.config.js

# Uninstall check on scratch databases (never the shared main databases)
tools/uninstall/run.sh 45 pgsql mariadb; tools/uninstall/run.sh 53 pgsql mariadb   # results in /tmp/csync-uninstall

# Full PHP 8.2/8.3/8.4 matrix: run by the lead with /home/claude/bin/ltifullmatrix (README-matrix.md)

# Mutation checks (mirrors the mutated file, restores it and mirrors again)
python3 tools/mutation_check.py --tree 53 --db pgsql

# Local fast feedback
php -l <file>; /tmp/moodlecs/vendor/bin/phpcs --standard=moodle classes tests db *.php
```

## Matrix

Results with dates are in ACCEPTANCE.md. Behat `@javascript` scenarios: none (the JS-only manual-completion button is
covered by Playwright). CI (`.github/workflows`, Moodle 4.5–5.3 × PostgreSQL/MariaDB) validated locally with the
family's workflow checker (97 checks, 0 errors) but **not executed**.
