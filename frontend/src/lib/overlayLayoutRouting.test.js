import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

const cwd = fileURLToPath(new URL('../..', import.meta.url));
const cli = fileURLToPath(new URL('../../node_modules/@playwright/test/cli.js', import.meta.url));
const collect = (config, baseURL) => execFileSync(process.execPath,
  [cli, 'test', '-c', config, '--list'], {
    cwd, encoding: 'utf8', env: { ...process.env, SMOKE_BASE_URL: baseURL },
  });

test('default smoke excludes the dedicated loopback overlay fixture for both base URLs', () => {
  for (const baseURL of ['http://localhost:5173', 'https://smoke-target.example.invalid']) {
    const list = collect('playwright.config.js', baseURL);
    assert.ok(!/overlay-layout\.spec\.js:/.test(list), 'dedicated overlay fixture must not enter default smoke');
    assert.match(list, /smoke\.spec\.js:/);
    assert.match(list, /Total: [1-9]\d* tests/);
  }
});

test('the dedicated built overlay fixture still collects all sixteen browser regressions', () => {
  const list = collect('playwright.overlay-layout.config.js', 'https://smoke-target.example.invalid');
  assert.equal((list.match(/overlay-layout\.spec\.js:/g) || []).length, 19);
  assert.match(list, /Total: 19 tests in 1 file/);
});
