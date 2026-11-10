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

test('real default smoke collection excludes the dedicated fake-data preview for both base URLs', () => {
  for (const baseURL of ['http://localhost:5173', 'https://smoke-target.example.invalid']) {
    const list = collect('playwright.config.js', baseURL);
    assert.doesNotMatch(list, /parent-text-size\.spec\.js:/);
    assert.match(list, /Total: [1-9]\d* tests/); // smoke collection itself remains active
  }
});

test('the dedicated built preview config collects all four parent cases', () => {
  const list = collect('e2e/parent-text-size-playwright.config.js', 'https://smoke-target.example.invalid');
  assert.equal((list.match(/parent-text-size\.spec\.js:/g) || []).length, 4);
  assert.match(list, /Total: 4 tests in 1 file/);
});
