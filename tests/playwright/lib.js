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
 * Helpers of the local_lticompletionsync browser suite: the stack library (login, seed) plus the plugin's own seed
 * extension (fixtures/csync_seed.php), which is run on a site through the stack's moodle_cli wrapper.
 */

const path = require('path');
const { execFileSync } = require('child_process');

const e2e = require(process.env.E2E_LIB || '/home/claude/e2e/lib/e2e');
const E2E_HOME = process.env.E2E_HOME || '/home/claude/e2e';
const SEED_SCRIPT = path.join(__dirname, 'fixtures', 'csync_seed.php');
const ROOTS = { a: process.env.A_ROOT || '/home/claude/moodle', b: process.env.B_ROOT || '/home/claude/moodle53' };

/**
 * Run a stage of the seed extension on a site.
 *
 * @param {string} site 'a' or 'b'
 * @param {object} args stage and options (without the leading dashes)
 * @returns {object} parsed JSON of the last output line
 */
function csync(site, args) {
  const opts = Object.entries(args).map(([k, v]) => `--${k}=${v}`);
  const cmd = `. ${E2E_HOME}/bin/e2e-env.sh; moodle_cli ${site} "$@"`;
  const out = execFileSync('bash', ['-c', cmd, 'csync', SEED_SCRIPT, `--root=${ROOTS[site]}`, ...opts],
    { encoding: 'utf8', timeout: 240000, maxBuffer: 16 * 1024 * 1024 });
  const lines = out.trim().split('\n');
  try {
    return JSON.parse(lines[lines.length - 1]);
  } catch (e) {
    throw new Error(`csync ${site} ${args.stage} did not return JSON:\n${out.slice(-2000)}`);
  }
}

/**
 * Build a scenario: platform tool type, tool resource + sender, platform course + activity + receiver.
 *
 * @param {object} seed stack seed
 * @param {object} o platform ('a'|'b'), tool ('a'|'b'), scenario, learner, grade, reusetype
 * @returns {object} {p1, t, p2, learner}
 */
function buildScenario(seed, o) {
  const p1 = csync(o.platform, { stage: 'platform1', scenario: o.scenario, toolbase: seed.urls[o.tool],
    reusetype: o.reusetype || 0 });
  const t = csync(o.tool, { stage: 'tool', scenario: o.scenario, issuer: p1.issuer, clientid: p1.clientid,
    deploymentid: p1.deploymentid, endpoint: p1.endpoint });
  const p2 = csync(o.platform, { stage: 'platform2', scenario: o.scenario, typeid: p1.typeid,
    loginurl: o.reusetype ? '' : t.loginurl, uuid: t.uuid, learners: o.learner, grade: o.grade === undefined ? '' : o.grade });
  return { p1, t, p2, learner: p2.learners[0] };
}

/**
 * Launch the platform activity as the learner and wait until the tool's published page is shown (completion on view).
 *
 * @param {import('@playwright/test').Page} page
 * @param {object} seed
 * @param {object} sc scenario from buildScenario
 * @param {string} platform 'a'|'b'
 * @param {string} tool 'a'|'b'
 */
async function launchAsLearner(page, seed, sc, platform, tool) {
  await e2e.login(page, platform, { username: sc.learner.username, password: sc.learner.password }, seed);
  await page.goto(sc.p2.viewurl);
  const host = new URL(seed.urls[tool]).host.replace(/\./g, '\\.');
  await e2e.waitForLaunch(page, new RegExp(`${host}/mod/page/view\\.php`), 90000);
  await page.waitForLoadState('load');
}

/**
 * Run the plugin's queued delivery tasks on a site through Moodle's own ad hoc task runner (so that the runs land in
 * the task log, as with cron).
 *
 * @param {string} site 'a' or 'b'
 * @returns {string} runner output
 */
function runDispatchTasks(site) {
  const cmd = `. ${E2E_HOME}/bin/e2e-env.sh; moodle_cli ${site} admin/cli/adhoc_task.php --execute ` +
    `--classname='\\local_lticompletionsync\\task\\dispatch_task'`;
  return execFileSync('bash', ['-c', cmd], { encoding: 'utf8', timeout: 240000 });
}

module.exports = { e2e, csync, buildScenario, launchAsLearner, runDispatchTasks };
