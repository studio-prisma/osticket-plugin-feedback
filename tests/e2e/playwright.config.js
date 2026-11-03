// @ts-check
const { defineConfig } = require('@playwright/test');
const { BASE } = require('./support');

module.exports = defineConfig({
  testDir: '.',
  testMatch: /.*\.spec\.js$/,
  // The specs share one database and change settings, so they run in file
  // order, one worker.
  workers: 1,
  fullyParallel: false,
  retries: 0,
  // Every php()/sql() call is a docker exec.
  timeout: 120000,
  reporter: [['list'], ['html', { open: 'never', outputFolder: '../../dev/local/e2e-report' }]],
  outputDir: '../../dev/local/e2e-results',
  use: {
    baseURL: BASE,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
    viewport: { width: 1280, height: 900 },
  },
});
