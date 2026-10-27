/**
 * Playwright configuration for the local_lticompletionsync two-site (platform A 4.5, tool B 5.3) browser tests.
 *
 * The suite needs the stack described in the seed file (LTI_SEED, default /home/claude/e2e/state/seed.json) and
 * uses its helper library (E2E_LIB, default /home/claude/e2e/lib/e2e). See README.md.
 *
 * @module     local_lticompletionsync/playwright.config
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const { defineConfig } = require('@playwright/test');

// The sandbox exports https_proxy; Chromium would send the test hosts to it (see the stack README).
process.env.NO_PROXY = ['.platform-a.test', '.tool-b.test', process.env.NO_PROXY || ''].filter(Boolean).join(',');
process.env.no_proxy = process.env.NO_PROXY;

module.exports = defineConfig({
  testDir: '.',
  testMatch: /.*\.spec\.js/,
  timeout: 300000,
  expect: { timeout: 20000 },
  workers: 1,
  fullyParallel: false,
  retries: 0,
  reporter: [['list']],
  outputDir: process.env.PW_OUTPUT_DIR || './test-results',
  use: {
    ignoreHTTPSErrors: false,
    launchOptions: { args: ['--proxy-bypass-list=*.platform-a.test;*.tool-b.test;localhost;127.0.0.1'] },
    viewport: { width: 1280, height: 900 },
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
});
