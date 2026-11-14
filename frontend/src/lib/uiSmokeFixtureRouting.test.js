import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

// Fixture-only specs (pilot-mount.html on the fake-data Vite server) must skip themselves when the
// live-site UI Smoke run sets SMOKE_BASE_URL, and must stay in the UI-foundation matcher.
const read = (name) => readFileSync(new URL(`../../${name}`, import.meta.url), 'utf8');
const FIXTURE_ONLY = ['ui-foundation-layout-guard', 'ui-foundation-overlay-gallery'];

test('fixture-only specs skip under SMOKE_BASE_URL', () => {
  for (const name of FIXTURE_ONLY) {
    assert.match(read(`e2e/${name}.spec.js`), /^test\.skip\(Boolean\(process\.env\.SMOKE_BASE_URL\),/m, `${name} must skip in UI Smoke`);
  }
});

test('UI foundation config still runs the fixture-only specs and does not set SMOKE_BASE_URL', () => {
  const text = read('playwright.ui-foundation.config.js');
  for (const name of FIXTURE_ONLY) assert.ok(text.includes(name), `${name} must stay in the ui-foundation matcher`);
  assert.ok(!text.includes('SMOKE_BASE_URL'));
});
