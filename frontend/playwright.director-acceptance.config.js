// @ts-check
import { mkdtempSync, chmodSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import smoke from './playwright.config.js';

const output = mkdtempSync(join(tmpdir(), 'alltrue-director-acceptance-'));
chmodSync(output, 0o700);
export default {
  ...smoke,
  testMatch: /director-path-acceptance\.spec\.js$/,
  testIgnore: [],
  reporter: 'list', // No HTML steps containing customer names or credential fill arguments.
  retries: 0, // Do not repeat the audited export automatically.
  outputDir: output,
  timeout: 90_000,
  use: { ...smoke.use, serviceWorkers: 'block', trace: 'off', screenshot: 'off', video: 'off' },
};
