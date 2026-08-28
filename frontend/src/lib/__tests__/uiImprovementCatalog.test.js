import assert from 'node:assert/strict';
import test from 'node:test';
import { getUiImprovementSummary, UI_IMPROVEMENTS } from '../uiImprovementCatalog.js';

test('UI improvement catalog keeps every finding actionable', () => {
  assert.ok(UI_IMPROVEMENTS.length > 0);
  for (const item of UI_IMPROVEMENTS) {
    assert.match(item.id, /^[a-z-]+$/);
    assert.ok(item.page && item.title && item.action);
    assert.ok(['P0', 'P1', 'P2'].includes(item.severity));
  }
});

test('UI improvement summary follows the catalog', () => {
  const summary = getUiImprovementSummary();
  assert.equal(summary.total, UI_IMPROVEMENTS.length);
  assert.equal(summary.P0 + summary.P1 + summary.P2, summary.total);
});
