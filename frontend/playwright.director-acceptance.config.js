// @ts-check
import { mkdtempSync, chmodSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import smoke from './playwright.config.js';

const output = mkdtempSync(join(tmpdir(), 'alltrue-director-acceptance-'));
chmodSync(output, 0o700);
// Playwright 1.64.1 ArtifactsRecorder honors this before taking its automatic
// failure aria snapshot. trace/screenshot/video off alone still captures DOM.
process.env.PLAYWRIGHT_NO_COPY_PROMPT = '1';
export default {
  ...smoke,
  testMatch: /director-path-acceptance\.spec\.js$/,
  testIgnore: [],
  reporter: 'list', // No HTML steps containing customer names or credential fill arguments.
  retries: 0, // Do not repeat the audited export automatically.
  preserveOutput: 'never', // Also remove generated error/source contexts on failure.
  outputDir: output,
  timeout: 90_000,
  use: { ...smoke.use, actionTimeout: 15_000, navigationTimeout: 20_000,
    serviceWorkers: 'block', trace: 'off', screenshot: 'off', video: 'off' },
};
