import assert from 'node:assert/strict';
import { test } from 'node:test';
import { mkdtempSync, writeFileSync, readFileSync, existsSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

for (const [profile, configName] of [
  ['director', 'playwright.director-acceptance.config.js'],
  ['default-credentialed', 'playwright.config.js'],
]) {
test(`private ${profile} capture excludes a synthetic DOM sentinel and cleans failure files`, () => {
  const scratch = mkdtempSync(join(tmpdir(), 'alltrue-director-privacy-proof-'));
  const frontend = fileURLToPath(new URL('../..', import.meta.url));
  const config = new URL('../../' + configName, import.meta.url).href;
  const playwright = new URL('../../node_modules/@playwright/test/index.mjs', import.meta.url).href;
  const sentinel = 'SYNTHETIC_PRIVATE_DOM_' + process.pid;
  let report;
  try {
    writeFileSync(join(scratch, 'probe.spec.mjs'), `import { test } from ${JSON.stringify(playwright)};
import { writeFileSync } from 'node:fs';
test('synthetic private failure', async ({ page }) => {
  await page.setContent('<main>' + process.env.DIRECTOR_PRIVACY_SENTINEL + '</main>');
  writeFileSync(process.env.DIRECTOR_PRIVACY_EXECUTION_MARKER, 'synthetic-dom-ready');
  throw new Error('UNVERIFIED: synthetic private failure.');
});`);
    writeFileSync(join(scratch, 'probe-reporter.cjs'), `const fs = require('node:fs');
module.exports = class {
  onBegin(config) { this.output = config.projects[0].outputDir; this.attachments = []; this.executions = []; }
  onTestEnd(test, result) {
    this.executions.push({ status: result.status, errorCount: result.errors.length,
      expectedFailure: result.errors.some(error => ['UNVERIFIED: synthetic private failure.', 'Error: UNVERIFIED: synthetic private failure.'].includes(error.message)) });
    for (const attachment of result.attachments) {
      const body = attachment.path && fs.existsSync(attachment.path) ? fs.readFileSync(attachment.path) : attachment.body;
      this.attachments.push({ name: attachment.name, private: Boolean(body && body.includes(process.env.DIRECTOR_PRIVACY_SENTINEL)), path: attachment.path });
    }
  }
  onEnd() { fs.writeFileSync(${JSON.stringify(join(scratch, 'result.json'))}, JSON.stringify({ output: this.output, attachments: this.attachments, executions: this.executions })); }
};`);
    for (const inspectBeforeCleanup of [true, false]) {
      const executionMarker = join(scratch, inspectBeforeCleanup ? 'inspection-executed' : 'cleanup-executed');
      report = undefined;
      rmSync(join(scratch, 'result.json'), { force: true });
      // Inspect a retained synthetic context as well, so cleanup cannot mask
      // an accidental reintroduction of DOM capture before the reporter reads it.
      writeFileSync(join(scratch, 'probe.config.mjs'), `import config from ${JSON.stringify(config)};
import { writeFileSync } from 'node:fs';
writeFileSync(${JSON.stringify(join(scratch, 'profile.json'))}, JSON.stringify({ reporter: config.reporter, preserveOutput: config.preserveOutput,
  trace: config.use.trace, screenshot: config.use.screenshot, video: config.use.video, noCopyPrompt: process.env.PLAYWRIGHT_NO_COPY_PROMPT === '1' }));
export default { ...config, retries: 0, outputDir: ${JSON.stringify(join(scratch, 'probe-output'))}, ${inspectBeforeCleanup ? "preserveOutput: 'always'," : ''} testDir: ${JSON.stringify(scratch)}, testMatch: /probe\\.spec\\.mjs$/, reporter: ${JSON.stringify(join(scratch, 'probe-reporter.cjs'))} };`);
      const result = spawnSync(process.execPath, [resolve(frontend, 'node_modules/playwright/cli.js'), 'test', '-c', join(scratch, 'probe.config.mjs')], {
        cwd: frontend, env: { ...process.env, SMOKE_BASE_URL: 'http://127.0.0.1', PLAYWRIGHT_NO_COPY_PROMPT: '', DIRECTOR_PRIVACY_SENTINEL: sentinel,
          DIRECTOR_PRIVACY_EXECUTION_MARKER: executionMarker },
        encoding: 'utf8', timeout: 60_000, maxBuffer: 1024 * 1024,
      });
      assert.equal(result.status, 1, 'the synthetic failure must actually execute');
      assert.ok(existsSync(executionMarker), 'the browser must execute setContent before privacy can pass');
      assert.equal(readFileSync(executionMarker, 'utf8'), 'synthetic-dom-ready', 'the execution marker must match this synthetic round');
      report = JSON.parse(readFileSync(join(scratch, 'result.json'), 'utf8'));
      assert.deepEqual(report.executions, [{ status: 'failed', errorCount: 1, expectedFailure: true }],
        'only the intended failure after DOM execution proves privacy');
      assert.equal(report.attachments.filter(item => item.private).length, 0, 'private DOM must never enter attachments');
      assert.equal(report.attachments.filter(item => ['screenshot', 'video', 'trace'].includes(item.name)).length, 0, 'private capture must not create screenshots, video or traces');
      assert.deepEqual(JSON.parse(readFileSync(join(scratch, 'profile.json'), 'utf8')),
        { reporter: 'list', preserveOutput: 'never', trace: 'off', screenshot: 'off', video: 'off', noCopyPrompt: true },
        'the actual private config must disable every capture mode, including retry tracing');
      if (inspectBeforeCleanup) assert.ok(report.attachments.some(item => item.path && existsSync(item.path)), 'the inspection must observe a real failure attachment');
      else assert.equal(report.attachments.filter(item => item.path && existsSync(item.path)).length, 0, 'failure files must be cleaned');
      assert.equal((result.stdout + result.stderr).includes(sentinel), false, 'private DOM must not enter console output');
      rmSync(report.output, { recursive: true, force: true });
    }
  } finally {
    // This also removes the intentionally unsafe baseline artifacts during RED.
    if (!report && existsSync(join(scratch, 'result.json'))) report = JSON.parse(readFileSync(join(scratch, 'result.json'), 'utf8'));
    if (report?.output?.startsWith(join(tmpdir(), 'alltrue-director-acceptance-'))) rmSync(report.output, { recursive: true, force: true });
    rmSync(scratch, { recursive: true, force: true });
  }
});
}

test('the uncredentialed default keeps synthetic diagnostic capture enabled', () => {
  const config = new URL('../../playwright.config.js', import.meta.url).href;
  const env = { ...process.env, PLAYWRIGHT_NO_COPY_PROMPT: '' };
  delete env.SMOKE_BASE_URL;
  const result = spawnSync(process.execPath, ['--input-type=module', '-e', `const {default: config} = await import(${JSON.stringify(config)});
console.log(JSON.stringify({trace:config.use.trace,screenshot:config.use.screenshot,video:config.use.video}));`],
  { env, encoding: 'utf8', timeout: 10_000 });
  assert.equal(result.status, 0);
  assert.deepEqual(JSON.parse(result.stdout), { trace: 'on-first-retry', screenshot: 'only-on-failure', video: 'retain-on-failure' });
});
