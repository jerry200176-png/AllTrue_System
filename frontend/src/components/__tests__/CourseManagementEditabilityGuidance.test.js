import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const pagePath = resolve(__dirname, '../../pages/CourseManagement.vue');
const source = readFileSync(pagePath, 'utf8');

describe('CourseManagement editability guidance', () => {
  it('routes each non-editable contract state to an existing destination', () => {
    for (const marker of [
      "'package_adjustment'",
      "'reconcile_usage'",
      "'new_contract'",
      'openPackageAdjustmentModal(course)',
      "emit('navigate', 'duplicate-review')",
      "emit('navigate', 'students')",
    ]) {
      expect(source).toContain(marker);
    }
  });

  it('preselects total-session adjustment for shared package guidance', () => {
    expect(source).toContain("purchaseForm.value.package_op = 'set'");
    expect(source).toContain('purchaseForm.value.sessions = getPackageTotalSessions(course)');
    expect(source).toContain('function canOpenEditabilityAction(action)');
  });
});
