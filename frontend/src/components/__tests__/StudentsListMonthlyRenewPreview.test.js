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

import { nextRenewalMonth, periodEndInMonth } from '../../lib/monthlyRenewalPreview';

describe('batch monthly renewal periods', () => {
  it('renews each course into the chosen month and skips courses already there', () => {
    expect(periodEndInMonth('2026-09-30', '2026-10')).toBe('2026-10-31');
    expect(periodEndInMonth('2026-09-14', '2026-10')).toBe('2026-10-14');
    expect(periodEndInMonth('2026-08-31', '2026-10')).toBe('2026-10-31');
    expect(periodEndInMonth('2026-10-31', '2026-10')).toBeNull();
    expect(nextRenewalMonth(['2026-10-31', '2026-09-30'])).toBe('2026-10');
  });

  it('students page offers one-dialog renewal and deep links monthly renew into it', () => {
    expect(source).toContain('data-testid="batch-monthly-renew"');
    expect(source).toContain('getRenewableMonthlyCourses(student.id).some((c) => c.id === targetCourse.id)) openBatchRenew(student)');
  });
});
