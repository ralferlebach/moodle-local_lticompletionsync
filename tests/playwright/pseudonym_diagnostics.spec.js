/*
 * This file is part of Moodle - https://moodle.org/
 *
 * Moodle is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Moodle is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Moodle.  If not, see <https://www.gnu.org/licenses/>.
 */

/*
 * Pseudonyms and diagnostics on the two-site stack, scenario "pa": platform A (4.5) with its own LTI 1.3 tool type,
 * tool B (5.3).
 *  - ltisource_ltipseudonym pseudonymises the `sub` for THIS type only (policy set in beforeAll, removed in afterAll);
 *    the tool reports completion for the pseudonymous user and A resolves it to the real learner (native completion);
 *  - after a rotation the old pseudonym fails closed: nothing is applied to anybody, the reason is visible on the tool
 *    (dead letter "identity_unresolved") and audited on the platform (ltisource_ltipseudonym miss + journal);
 *  - CS-G6: tool_ltidiagnostics (>= 2026102600) shows the transfer with its correlation id for the connection on both sites.
 */

const { test, expect } = require('@playwright/test');
const { e2e, csync, buildScenario, launchAsLearner, runDispatchTasks } = require('./lib');

e2e.screenshotOnFailure(test);

test.describe.serial('pseudonymous subject: A (4.5 platform, own type) <- B (5.3 tool)', () => {
  let seed;
  let sc;
  let t0;
  let learner2;

  test.beforeAll(() => {
    seed = e2e.loadSeed();
    t0 = Math.floor(Date.now() / 1000) - 5;
    sc = buildScenario(seed, { platform: 'a', tool: 'b', scenario: 'pa', learner: 'csyncstudentpa,csyncstudentpa2',
      grade: 10 });
    learner2 = sc.p2.learners[1];
    const policy = csync('a', { stage: 'pseudo', typeid: sc.p1.typeid, action: 'set' });
    expect(policy.active).toBe(true);
    expect(policy.pseudotype).toBe('random_persisted');
  });

  test.afterAll(() => {
    // The stack is shared: remove the policy again (it only ever applied to this scenario's own tool type).
    if (sc) {
      const policy = csync('a', { stage: 'pseudo', typeid: sc.p1.typeid, action: 'clear' });
      expect(policy.pseudotype).toBe('none');
    }
  });

  const state = (u) => csync('a', { stage: 'state', cmid: sc.p2.cmid, username: u.username });

  test('the pseudonym is resolved to the real learner and completion arrives natively', async ({ page }) => {
    await launchAsLearner(page, seed, sc, 'a', 'b');
    const tool = csync('b', { stage: 'toolrows', scenario: 'pa' });
    expect(tool.subs.length).toBe(1);
    expect(tool.subs[0]).not.toMatch(/^\d+$/);
    expect(tool.subs[0]).not.toBe(String(sc.learner.id));
    runDispatchTasks('b');
    const after = state(sc.learner);
    expect(after.state).toBe(1);
    expect(after.rcv.status).toBe('applied');
    expect(state(learner2).state).toBe(0);
    const journal = csync('a', { stage: 'journal', from: t0 }).entries;
    const resolved = journal.find((e) => e.event === 'identity_resolved');
    expect(resolved.detail).toBe('pseudonym');
    expect(resolved.userid).toBe(sc.learner.id);
  });

  test('after a rotation the old pseudonym fails closed, visibly and audited', async ({ page }) => {
    const before = csync('a', { stage: 'pseudo', typeid: sc.p1.typeid, action: 'show' });
    expect(csync('a', { stage: 'pseudo', typeid: sc.p1.typeid, action: 'rotate' }).rotated).toBeGreaterThanOrEqual(1);
    csync('b', { stage: 'revoke', scenario: 'pa', cmid: sc.t.pagecmid });
    csync('b', { stage: 'deliver' });
    const rows = csync('b', { stage: 'toolrows', scenario: 'pa' }).rows;
    expect(rows[0].version).toBe(2);
    expect(rows[0].status).toBe('dead');
    expect(rows[0].lasterror).toBe('identity_unresolved');
    // Nothing applied to anybody: the learner keeps the completion of version 1, the other learner stays incomplete.
    const now = state(sc.learner);
    expect(now.state).toBe(1);
    expect(now.rcv.appliedversion).toBe(1);
    expect(state(learner2).state).toBe(0);
    // Audit on the platform: the pseudonym plugin recorded the miss, this plugin's journal the refusal.
    const after = csync('a', { stage: 'pseudo', typeid: sc.p1.typeid, action: 'show' });
    expect(after.misses).toBeGreaterThan(before.misses);
    const journal = csync('a', { stage: 'journal', from: t0 }).entries;
    expect(journal.some((e) => e.detail === 'rejected:identity_unresolved')).toBe(true);
    // Visible reason for the tool administrator.
    await page.context().clearCookies();
    await e2e.login(page, 'b', 'admin', seed);
    await page.goto(`${seed.urls.b}/local/lticompletionsync/index.php`);
    await expect(page.locator('.local-lticompletionsync-journal')).toContainText('identity_unresolved');
  });

  test('CS-G6: tool_ltidiagnostics shows the transfer with its correlation id on both sites', () => {
    const toolside = csync('b', { stage: 'journal', from: t0 });
    expect(toolside.logstore).toBeGreaterThan(0);
    const sent = toolside.entries.find((e) => e.event === 'sent');
    expect(sent.corr).toMatch(/^[0-9a-f-]{36}$/);
    // Tool connection on B: the transfer (correlation id) and the delivery task runs appear in the timeline.
    const diag = csync('b', { stage: 'diag', key: `tool:${sc.t.regid}:${sc.t.deploymentrow}`, from: t0, corr: sent.corr });
    expect(diag.installed).toBe(true);
    expect(diag.correlation_found).toBe(true);
    expect(diag.plugin_items.some((i) => i.source === 'tasklog' && /dispatch_task/.test(i.label))).toBe(true);
    // Platform connection on A: the same correlation id, carried in the signed message, appears there too.
    const platform = csync('a', { stage: 'journal', from: t0, corr: sent.corr });
    expect(platform.entries.some((e) => e.event === 'http_received')).toBe(true);
    const pdiag = csync('a', { stage: 'diag', key: `platform:${sc.p1.typeid}`, from: t0, corr: sent.corr });
    expect(pdiag.installed).toBe(true);
    expect(pdiag.correlation_found).toBe(true);
  });
});
