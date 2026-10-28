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
 * CS-03 / CS-05 / CS-06 on the two-site stack (B 5.3 tool -> A 4.5 platform, own registration, two learners):
 *  - concurrent delivery storm: K versions, each sent twice (different jti) plus a byte-identical replay, all at the
 *    same time through separate php-fpm workers (real process parallelism, separate DB connections) - replays refused,
 *    versions applied in strictly increasing order, at most once each, final state = provider state;
 *  - six tool-side CLI processes deliver the same due rows at the same time - every version sent exactly once;
 *  - interrupted network (platform endpoint dropped at the web server) and resumption with "Retry now";
 *  - AGS reference check against a platform whose completion endpoint is absent (stock Moodle AGS path): progress
 *    without scoreGiven on a dedicated line item, graded item and native completion untouched;
 *  - the live outcome report of both sites (success, failed, revoked, retried with causes).
 * Needs the stack lock: run inside `flock /tmp/e2e_stack.lock` (nginx rules are changed and restored).
 */

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { e2e, csync, buildScenario, launchAsLearner, loadSeed, parallelCli, endpointBlock, endpointUnblockAll } =
  require('./lib');

/**
 * Save an evidence file in the test's output directory.
 *
 * @param {string} name
 * @param {object} data
 */
function save(name, data) {
  fs.mkdirSync(test.info().outputDir, { recursive: true });
  fs.writeFileSync(path.join(test.info().outputDir, name), JSON.stringify(data, null, 2));
}

const P = 'a';
const T = 'b';
const SCENARIO = 'rs';
const LEARNERS = 'csyncstudentrs1,csyncstudentrs2';
const GRADE = 64;

e2e.screenshotOnFailure(test);

test.describe.serial('robustness B (5.3 tool) -> A (4.5 platform)', () => {
  let seed;
  let sc;
  const from = Math.floor(Date.now() / 1000) - 5;
  const state = (username) => csync(P, { stage: 'state', cmid: sc.p2.cmid, username });
  const rows = () => csync(T, { stage: 'outrows', scenario: SCENARIO }).rows;

  test.beforeAll(() => {
    seed = loadSeed();
    sc = buildScenario(seed, { platform: P, tool: T, scenario: SCENARIO, learner: LEARNERS, grade: GRADE });
  });

  test.afterAll(() => {
    endpointUnblockAll();
    try {
      csync(T, { stage: 'sender', scenario: SCENARIO, agsprogress: 0, action: 'resetpartner' });
    } catch (e) {
      // Best effort.
    }
  });

  test('both learners complete; the completions arrive natively', async ({ browser }) => {
    for (const learner of sc.p2.learners) {
      const context = await browser.newContext();
      const page = await context.newPage();
      await launchAsLearner(page, seed, { ...sc, learner }, P, T);
      await context.close();
    }
    csync(T, { stage: 'deliver' });
    for (const learner of sc.p2.learners) {
      expect(state(learner.username).state).toBe(1);
    }
    expect(rows().every((r) => r.status === 'acked')).toBe(true);
  });

  test('concurrent storm: duplicates, replays and out-of-order versions in parallel workers', async () => {
    const learner = sc.p2.learners[0];
    const storm = csync(T, { stage: 'storm', scenario: SCENARIO, versions: 6 });
    save('storm.json', storm);
    expect(storm.requests).toBe(18);
    for (const a of storm.answers.filter((x) => x.kind === 'replay')) {
      // Byte-identical replay: refused when the first copy was seen first, otherwise the first copy is refused.
      expect([200, 409]).toContain(a.http);
    }
    const byJws = storm.answers.filter((a) => a.http === 409);
    expect(byJws.length).toBeGreaterThanOrEqual(6);
    expect(byJws.every((a) => a.error === 'replay')).toBe(true);
    for (const a of storm.answers.filter((x) => x.http === 200)) {
      expect(['applied', 'rejected', 'received']).toContain(a.status);
      if (a.status === 'rejected') {
        expect(a.reason).toBe('stale_version');
      }
    }
    // The tool's own delivery converges to the final version (also when the newest message met a "busy" target).
    csync(T, { stage: 'deliver' });
    const applied = csync(P, { stage: 'applied', cmid: sc.p2.cmid, username: learner.username });
    save('storm-applied.json', applied);
    const versions = applied.sequence.map((s) => s.version);
    for (let i = 1; i < versions.length; i++) {
      expect(versions[i]).toBeGreaterThan(versions[i - 1]);
    }
    expect(applied.rcv.remoteversion).toBe(storm.final);
    expect(applied.rcv.appliedversion).toBe(storm.final);
    expect(applied.rcv.remotestate).toBe(storm.finalstate);
    expect(state(learner.username).state).toBe(storm.finalstate);
    expect(Number(state(learner.username).grade)).toBe(GRADE);
    const row = rows().find((r) => r.status !== 'acked');
    expect(row).toBeUndefined();
  });

  test('six tool processes deliver the same due rows at the same time: each version is sent once', async () => {
    expect(csync(T, { stage: 'resync', scenario: SCENARIO }).versions).toBeGreaterThanOrEqual(2);
    const before = rows();
    expect(before.every((r) => r.status === 'pending')).toBe(true);
    const runs = await parallelCli(T, 6, ['--deliver']);
    save('parallel.json', runs);
    expect(runs.every((r) => r.code === 0)).toBe(true);
    const after = rows();
    for (const r of after) {
      expect(r.status).toBe('acked');
      expect(r.sent).toBe(1);
    }
    for (const learner of sc.p2.learners) {
      const a = csync(P, { stage: 'applied', cmid: sc.p2.cmid, username: learner.username });
      expect(a.rcv.status).toBe('applied');
    }
  });

  test('interrupted network: retry with backoff, nothing applied, resumption after the outage', async () => {
    const learner = sc.p2.learners[1];
    const before = state(learner.username).state;
    csync(T, { stage: 'resync', scenario: SCENARIO });
    endpointBlock(P, 'drop');
    try {
      csync(T, { stage: 'deliver' });
      const down = rows();
      expect(down.every((r) => r.status === 'retry')).toBe(true);
      expect(down.every((r) => /^(http_0|transport_|partner_)/.test(r.lasterror || ''))).toBe(true);
      expect(state(learner.username).state).toBe(before);
      // Still down: "Retry now" + delivery fails again, bounded (no endless loop), attempts counted.
      csync(T, { stage: 'retrynow', scenario: SCENARIO });
      csync(T, { stage: 'deliver' });
      expect(rows().every((r) => r.status === 'retry' && r.attempts >= 2)).toBe(true);
    } finally {
      endpointBlock(P, null);
    }
    expect(csync(T, { stage: 'retrynow', scenario: SCENARIO }).due).toBe(2);
    csync(T, { stage: 'deliver' });
    expect(rows().every((r) => r.status === 'acked')).toBe(true);
    expect(state(learner.username).state).toBe(before);
  });

  test('AGS reference check: platform without completion endpoint (stock Moodle AGS)', async () => {
    const learner = sc.p2.learners[0];
    const pre = csync(P, { stage: 'agsstate', cmid: sc.p2.cmid, username: learner.username });
    const graded = pre.items.find((i) => i.itemnumber === 0);
    expect(graded.grade).toBe(GRADE);
    endpointBlock(P, 'absent');
    try {
      const sender = csync(T, { stage: 'sender', scenario: SCENARIO, agsprogress: 1, action: 'resetpartner' });
      expect(sender.agsprogress).toBe(1);
      csync(T, { stage: 'resync', scenario: SCENARIO });
      csync(T, { stage: 'deliver' });
      const level = csync(T, { stage: 'sender', scenario: SCENARIO });
      expect(level.partnerstatus).toBe('noplugin');
      save('ags-level.json', level);
      const out = rows();
      save('ags-rows.json', out);
      expect(level.level.ags.progress).toBeGreaterThanOrEqual(1);
      expect(level.level.key).toBe('toolonly');
      expect(out.every((r) => r.status === 'sent' && r.agsstatus === 'sent')).toBe(true);
      expect(out.every((r) => r.lasterror === 'tool_only_no_confirmation')).toBe(true);
    } finally {
      endpointBlock(P, null);
      csync(T, { stage: 'sender', scenario: SCENARIO, agsprogress: 0, action: 'resetpartner' });
    }
    const post = csync(P, { stage: 'agsstate', cmid: sc.p2.cmid, username: learner.username });
    save('ags-platform.json', { pre, post });
    // The graded item and its grade history are untouched; a dedicated progress line item exists, without a score.
    const graded2 = post.items.find((i) => i.itemnumber === 0);
    expect(graded2.grade).toBe(GRADE);
    expect(graded2.history).toBe(graded.history);
    const progress = post.lineitems.find((l) => l.tag === 'lticompletionsync-progress');
    expect(progress).toBeDefined();
    const item = post.items.find((i) => i.id === progress.gradeitemid);
    expect(item.grademax).toBe(1);
    expect([null, 'none']).toContain(item.grade);
    // Stock Moodle does not turn AGS progress into native completion: no promise was made, nothing changed.
    expect(post.state).toBe(pre.state);
  });

  test('live outcome report of both sites', async () => {
    // A provider-side revocation of both learners, delivered cooperatively again.
    expect(csync(T, { stage: 'revoke', scenario: SCENARIO, cmid: sc.t.pagecmid }).revoked).toBe(2);
    csync(T, { stage: 'deliver' });
    for (const learner of sc.p2.learners) {
      expect(state(learner.username).state).toBe(0);
    }
    const tool = csync(T, { stage: 'report', hours: 2 });
    const platform = csync(P, { stage: 'report', hours: 2 });
    save('report.json', { tool, platform, from });
    // The scenario's current connections (earlier runs of the scenario left older connections with the same label).
    const sender = tool[String(csync(T, { stage: 'sender', scenario: SCENARIO }).id)];
    expect(sender.success).toBeGreaterThanOrEqual(4);
    expect(sender.revoked).toBeGreaterThanOrEqual(1);
    expect(Object.keys(sender.retried).length).toBeGreaterThanOrEqual(1);
    const receivers = Object.entries(platform).filter(([, c]) => c.role === 'receiver' && c.label.includes(`CSYNC tool ${SCENARIO}`));
    const receiver = receivers.sort((x, y) => Number(y[0]) - Number(x[0]))[0][1];
    expect(receiver.success).toBeGreaterThanOrEqual(2);
    expect(receiver.revoked).toBeGreaterThanOrEqual(1);
    expect((receiver.retried.duplicate || 0) + (receiver.retried.stale_version || 0)).toBeGreaterThanOrEqual(1);
  });
});
