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

const fs = require('fs');
const path = require('path');
const { execFileSync, spawn } = require('child_process');

const e2e = require(process.env.E2E_LIB || '/home/claude/e2e/lib/e2e');
const E2E_HOME = process.env.E2E_HOME || '/home/claude/e2e';
const SEED_SCRIPT = path.join(__dirname, 'fixtures', 'csync_seed.php');
const ROOTS = { a: process.env.A_ROOT || '/home/claude/moodle', b: process.env.B_ROOT || '/home/claude/moodle53' };
// Same-version partner sites of the stack (e2e-pair-setup): same trees, own database and dataroot.
ROOTS.a2 = ROOTS.a;
ROOTS.b2 = ROOTS.b;
const PAIRS = {
  a2: { url: process.env.A2_URL || 'https://a2.platform-a.test', password: 'E2e-Admin!2026-A2' },
  b2: { url: process.env.B2_URL || 'https://b2.tool-b.test', password: 'E2e-Admin!2026-B2' },
};
const NGINX_CONF = path.join(E2E_HOME, 'conf', 'nginx.conf');
const BLOCK_MARK = '# csync-e2e-block';

/**
 * The stack seed extended by the partner sites a2/b2 (urls, admin credentials).
 *
 * @returns {object}
 */
function loadSeed() {
  const seed = e2e.loadSeed();
  for (const [site, p] of Object.entries(PAIRS)) {
    seed.urls[site] = seed.urls[site] || p.url;
    seed.admins[site] = seed.admins[site] || { site, username: 'admin', password: p.password, role: 'admin' };
  }
  return seed;
}

/**
 * Whether a partner site (a2/b2) of the stack is installed.
 *
 * @param {string} site
 * @returns {boolean}
 */
function siteInstalled(site) {
  if (site === 'a' || site === 'b') {
    return true;
  }
  try {
    execFileSync('bash', ['-c', `. ${E2E_HOME}/bin/e2e-env.sh; site_installed ${site}`], { stdio: 'ignore' });
    return true;
  } catch (e) {
    return false;
  }
}

/**
 * Path of this plugin's CLI script relative to a site's tree root.
 *
 * @param {string} site
 * @returns {string}
 */
function cliPath(site) {
  return (site.startsWith('b') ? 'public/' : '') + 'local/lticompletionsync/cli/sync.php';
}

/**
 * Run N processes of this plugin's CLI on a site AT THE SAME TIME (each its own PHP process and DB connection).
 *
 * @param {string} site
 * @param {number} n
 * @param {string[]} args CLI options
 * @returns {Promise<object[]>} exit code and output of every process
 */
function parallelCli(site, n, args) {
  const cmd = `. ${E2E_HOME}/bin/e2e-env.sh; moodle_cli ${site} "$@"`;
  const runs = [];
  for (let i = 0; i < n; i++) {
    runs.push(new Promise((resolve) => {
      const child = spawn('bash', ['-c', cmd, 'csync', cliPath(site), ...args]);
      let out = '';
      child.stdout.on('data', (d) => { out += d; });
      child.stderr.on('data', (d) => { out += d; });
      child.on('close', (code) => resolve({ code, out }));
    }));
  }
  return Promise.all(runs);
}

/**
 * Block (or unblock) this plugin's endpoint of a site at the web server: 'drop' closes the connection without an answer
 * (interrupted network), 'absent' answers 404 like a Moodle without the plugin. Restored with endpointBlock(site, null).
 * Only use inside the stack lock (flock /tmp/e2e_stack.lock), see README.md.
 *
 * @param {string} site
 * @param {string|null} mode 'drop' | 'absent' | null
 */
function endpointBlock(site, mode) {
  const host = new URL(loadSeed().urls[site]).host;
  let conf = fs.readFileSync(NGINX_CONF, 'utf8').split('\n').filter((l) => !l.includes(BLOCK_MARK)).join('\n');
  if (mode) {
    const rule = `    location = /local/lticompletionsync/endpoint.php { return ${mode === 'drop' ? 444 : 404}; } ${BLOCK_MARK}`;
    conf = conf.replace(`server_name ${host};`, `server_name ${host};\n${rule}`);
  }
  fs.writeFileSync(NGINX_CONF, conf);
  execFileSync('nginx', ['-c', NGINX_CONF, '-s', 'reload']);
  execFileSync('bash', ['-c', 'sleep 1']);
}

/**
 * Remove every endpoint block (safety net for afterAll / process exit).
 */
function endpointUnblockAll() {
  const conf = fs.readFileSync(NGINX_CONF, 'utf8');
  if (conf.includes(BLOCK_MARK)) {
    fs.writeFileSync(NGINX_CONF, conf.split('\n').filter((l) => !l.includes(BLOCK_MARK)).join('\n'));
    execFileSync('nginx', ['-c', NGINX_CONF, '-s', 'reload']);
  }
}
process.on('exit', () => {
  try {
    endpointUnblockAll();
  } catch (e) {
    // Best effort.
  }
});

/**
 * Accessibility check with axe-core (WCAG 2.0/2.1/2.2 A and AA rules) of the given selectors on the current page.
 * axe-core is loaded from AXE_CORE (path of axe.min.js); without it the check fails instead of being skipped.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string[]} include CSS selectors to check
 * @returns {Promise<object[]>} violations
 */
async function axeViolations(page, include) {
  const file = process.env.AXE_CORE || '/tmp/csync-axe/node_modules/axe-core/axe.min.js';
  await page.addScriptTag({ path: file });
  return page.evaluate(async (inc) => {
    const ctx = { include: inc.filter((s) => document.querySelector(s)).map((s) => [s]) };
    if (!ctx.include.length) {
      return [{ id: 'nothing-to-check', nodes: [] }];
    }
    const r = await window.axe.run(ctx, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] } });
    return r.violations.map((v) => ({ id: v.id, impact: v.impact, nodes: v.nodes.map((n) => n.html.slice(0, 200)) }));
  }, include);
}

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

module.exports = { e2e, csync, buildScenario, launchAsLearner, runDispatchTasks, loadSeed, siteInstalled, parallelCli,
  endpointBlock, endpointUnblockAll, axeViolations };
