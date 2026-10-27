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
 * End-to-end user stories of local_lticompletionsync on the two-site stack:
 *  - B (5.3, tool) -> A (4.5, platform) with the stack's registration,
 *  - A (4.5, tool) -> B (5.3, platform) with a second registration created by fixtures/csync_seed.php,
 *  - same-site loopback (sender and receiver on one site, CS-G1) on A (4.5) and on B (5.3).
 * Each: the learner launches the external tool activity, reads the published page on the tool (completion on view),
 * the completion arrives as NATIVE completion of the same mod_lti activity, an existing grade is untouched, the learner
 * cannot toggle it himself, a revocation stays after cron and reload, a duplicate / older message changes nothing.
 */

const { test, expect } = require('@playwright/test');
const { e2e, csync, buildScenario, launchAsLearner } = require('./lib');

const DIRECTIONS = [
  { title: 'B (5.3 tool) -> A (4.5 platform), stack registration', platform: 'a', tool: 'b', scenario: 'ba',
    learner: 'csyncstudentba', grade: 55, reuse: true },
  { title: 'A (4.5 tool) -> B (5.3 platform), second registration', platform: 'b', tool: 'a', scenario: 'ab',
    learner: 'csyncstudentab', grade: 40 },
  { title: 'loopback on A (4.5): sender and receiver on one site', platform: 'a', tool: 'a', scenario: 'la',
    learner: 'csyncstudentla', grade: 30 },
  { title: 'loopback on B (5.3): sender and receiver on one site', platform: 'b', tool: 'b', scenario: 'lb',
    learner: 'csyncstudentlb', grade: 20 },
];

e2e.screenshotOnFailure(test);

/**
 * Log out (best effort) and log the learner into a site again (after a loopback launch the session is the LTI user).
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} site
 * @param {object} sc
 * @param {object} seed
 */
async function relogin(page, site, sc, seed) {
  // Dropping the cookies ends every session (the stack's logout helper can be aborted by the logout redirect).
  await page.context().clearCookies();
  await e2e.login(page, site, { username: sc.learner.username, password: sc.learner.password }, seed);
}

for (const d of DIRECTIONS) {
  test.describe.serial(d.title, () => {
    let seed;
    let sc;

    test.beforeAll(() => {
      seed = e2e.loadSeed();
      sc = buildScenario(seed, { platform: d.platform, tool: d.tool, scenario: d.scenario, learner: d.learner,
        grade: d.grade, reusetype: d.reuse ? seed.lti.platform.type_id : 0 });
    });

    const state = () => csync(d.platform, { stage: 'state', cmid: sc.p2.cmid, username: sc.learner.username });

    test('completion arrives natively, the grade is untouched and the learner cannot toggle it', async ({ page }) => {
      const before = state();
      expect(before.state).toBe(0);
      expect(before.grade).not.toBeNull();
      await launchAsLearner(page, seed, sc, d.platform, d.tool);
      expect(csync(d.tool, { stage: 'deliver' }).delivered).toBeGreaterThanOrEqual(1);
      const after = state();
      expect(after.state).toBe(1);
      expect(after.overrideby).toBeNull();
      expect(after.rcv.status).toBe('applied');
      expect(after.rcv.appliedversion).toBe(1);
      expect(after.grade).toBe(before.grade);
      expect(after.gradeitems).toBe(before.gradeitems);
      expect(after.canself).toBe(false);

      // The platform course page shows the native completion; the learner's own toggle is refused server side.
      await relogin(page, d.platform, sc, seed);
      await page.goto(sc.p2.courseurl);
      const toggle = page.locator(`[data-action="toggle-manual-completion"][data-cmid="${sc.p2.cmid}"]`).first();
      await expect(toggle).toBeVisible();
      await expect(toggle).toHaveAttribute('data-toggletype', 'manual:undo');
      await toggle.click();
      await page.waitForTimeout(1500);
      await page.reload();
      expect(state().state).toBe(1);
    });

    test('revocation is applied and stays after cron and reload', async ({ page }) => {
      expect(csync(d.tool, { stage: 'revoke', scenario: d.scenario, cmid: sc.t.pagecmid }).revoked).toBeGreaterThanOrEqual(1);
      csync(d.tool, { stage: 'deliver' });
      let now = state();
      expect(now.state).toBe(0);
      expect(now.rcv.appliedversion).toBe(2);
      csync(d.platform, { stage: 'cron' });
      csync(d.tool, { stage: 'cron' });
      now = state();
      expect(now.state).toBe(0);
      await relogin(page, d.platform, sc, seed);
      await page.goto(sc.p2.courseurl);
      const toggle = page.locator(`[data-action="toggle-manual-completion"][data-cmid="${sc.p2.cmid}"]`).first();
      await expect(toggle).toHaveAttribute('data-toggletype', 'manual:mark-done');
    });

    test('a duplicate and an older message change nothing', async () => {
      const results = csync(d.tool, { stage: 'replay', scenario: d.scenario }).results;
      expect(results.length).toBeGreaterThanOrEqual(2);
      const current = results.find((r) => r.version === 2);
      const older = results.find((r) => r.version === 1);
      expect(current.status).toBe('applied');
      expect(older.status).toBe('rejected');
      expect(older.reason).toBe('stale_version');
      expect(state().state).toBe(0);
    });

    test('the administrators see the declared levels and the journal', async ({ page }) => {
      await page.context().clearCookies();
      await e2e.login(page, d.platform, 'admin', seed);
      await page.goto(`${seed.urls[d.platform]}/local/lticompletionsync/index.php`);
      await expect(page.locator('.local-lticompletionsync-connections')).toContainText('Receiver');
      await expect(page.locator('.local-lticompletionsync-journal')).toContainText('completion_revoked');
      if (d.tool !== d.platform) {
        await page.goto('about:blank');
        await page.context().clearCookies();
        await e2e.login(page, d.tool, 'admin', seed);
      }
      await page.goto(`${seed.urls[d.tool]}/local/lticompletionsync/index.php`);
      await expect(page.locator('.local-lticompletionsync-connections')).toContainText('Cooperative');
      await expect(page.locator('.local-lticompletionsync-journal')).toContainText('acknowledged');
    });
  });
}
