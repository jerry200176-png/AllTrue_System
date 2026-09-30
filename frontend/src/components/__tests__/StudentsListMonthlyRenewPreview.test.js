import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { addMonthsToPeriodEnd, applyMonthlyRenewalPreview, canSubmitMonthlyRenewal, invalidateMonthlyRenewalPreview } from '../../lib/monthlyRenewalPreview';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/StudentsList.vue'), 'utf8');

describe('addMonthsToPeriodEnd', () => {
  it('keeps month-end periods at month-end and clamps short months', () => {
    expect(addMonthsToPeriodEnd('2026-09-30', 1)).toBe('2026-10-31');
    expect(addMonthsToPeriodEnd('2026-10-31', 1)).toBe('2026-11-30');
    expect(addMonthsToPeriodEnd('2026-01-31', 1)).toBe('2026-02-28');
    expect(addMonthsToPeriodEnd('2026-09-14', 1)).toBe('2026-10-14');
    expect(addMonthsToPeriodEnd('2026-11-30', 2)).toBe('2027-01-31');
    expect(addMonthsToPeriodEnd('', 1, new Date(2026, 8, 15))).toBe('2026-10-15');
  });
});

describe('StudentsList monthly renewal preview', () => {
  it('a successful preview enables submit (the students page used to leave it grey forever)', () => {
    const form = {};
    invalidateMonthlyRenewalPreview(form, '2026-10-31');
    expect(canSubmitMonthlyRenewal(form, '2026-10-31')).toBe(false);
    applyMonthlyRenewalPreview(form, { severity: 'ok', proposed_course: { start_date: '2026-10-01' } });
    expect(canSubmitMonthlyRenewal(form, '2026-10-31')).toBe(true);
  });

  it('wires preview state, warnings, submitting and the old end date', () => {
    expect(source).toContain('invalidateMonthlyRenewalPreview(renewMonthlyForm.value, targetEnd)');
    expect(source).toContain('applyMonthlyRenewalPreview(renewMonthlyForm.value, json)');
    expect(source).toContain(':warnings="renewMonthlyWarnings"');
    expect(source).toContain(':submitting="renewMonthlySubmitting"');
    expect(source.match(/end_date: c\.end_date \|\| \(c\.EndDate/g)?.length).toBe(2);
  });
});
