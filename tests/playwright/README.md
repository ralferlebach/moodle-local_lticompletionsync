# local_lticompletionsync - Playwright end-to-end tests

Two-site stack (`/home/claude/e2e/README.md`): site A = Moodle 4.5 (`https://a.platform-a.test`), site B = Moodle 5.3
(`https://b.tool-b.test`); the plugin is installed on both. The suite reads the stack seed (`LTI_SEED`, default
`/home/claude/e2e/state/seed.json`), uses the stack library (`E2E_LIB`, default `/home/claude/e2e/lib/e2e`) for login
and runs the plugin's **seed extension** `fixtures/csync_seed.php` on a site through the stack's `moodle_cli`
(`E2E_HOME`, default `/home/claude/e2e`; tree roots `A_ROOT`/`B_ROOT`). The extension creates its own courses
(`CSYNC-T-<scenario>` on the tool, `CSYNC-P-<scenario>` on the platform), learners `csyncstudent<scenario>`, LTI 1.3
tool types, enrol_lti registrations/deployments and the plugin's connections; it never changes the stack's own courses.
It enables `allowprivatetargets` on the tool site (the stack resolves its hosts to 127.0.0.1).

| Scenario | Platform | Tool | Registration |
|---|---|---|---|
| `ba` | A 4.5 | B 5.3 | the stack's registration (type `seed.lti.platform.type_id`) |
| `ab` | B 5.3 | A 4.5 | a second registration created by the extension |
| `la` | A 4.5 | A 4.5 | same-site loopback (CS-G1) |
| `lb` | B 5.3 | B 5.3 | same-site loopback (CS-G1) |
| `pa` | A 4.5 (own type, pseudonymised) | B 5.3 | second registration; `pseudonym_diagnostics.spec.js` |

Per scenario (serial): (1) the learner launches the external tool activity, reads the published page on the tool
(completion on view), the tool delivers, the platform shows **native** completion of the same mod_lti activity
(`course_modules_completion`, no override), the existing grade and the number of grade items are unchanged, the learner
has no `togglecompletion` capability and a click on the "Done" button does not change the state; (2) a provider-side
revocation arrives, stays after the platform's and the tool's cron and after a reload ("Mark as done" shown); (3) a
duplicate of the current version is answered `applied`, an older version `rejected/stale_version`, nothing changes;
(4) the administrators of both sites see the declared level (Receiver / Cooperative) and the journal.

```bash
# stack up (e2e-up); plugin mirrored + upgraded on both sites (ltirun 45 pgsql ... upgrade; ltirun 53 pgsql ... upgrade)
cd /home/claude/work/moodle-local_lticompletionsync
NODE_PATH=/home/claude/e2e/node_modules /home/claude/e2e/node_modules/.bin/playwright test -c tests/playwright/playwright.config.js
```

`pseudonym_diagnostics.spec.js` (scenario `pa`): a policy of ltisource_ltipseudonym (random persisted pseudonyms per
deployment) is set for the scenario's OWN tool type only and removed in `afterAll` (the stack is shared). (1) The tool
receives a pseudonymous `sub`, reports completion for it and A resolves it to the real learner (native completion,
journal `identity_resolved` = `pseudonym`, second learner untouched). (2) After a rotation the revocation for the old
pseudonym fails closed: B dead letter `identity_unresolved` (visible in B's journal), nothing applied on A, a
`service_pseudonym_miss` audit row of ltisource_ltipseudonym and a `rejected:identity_unresolved` journal row on A.
(3) CS-G6: the correlation id produced by the transfer appears in tool_ltidiagnostics' (>= 2026102600) timeline/evidence
for the tool connection on B (with the delivery task runs, run through Moodle's ad hoc task CLI) and for the platform
connection on A.

`npm run check` runs `node --check` on all files. Delivery is triggered with the extension's `deliver` stage (instead of
waiting for cron); the `cron` stage runs completion and plugin tasks and only this plugin's ad hoc tasks.

Status: executed 2026-10-26 by the plugin agent, Chromium: **19/19 passed** (16 + 3). Not part of CI.
