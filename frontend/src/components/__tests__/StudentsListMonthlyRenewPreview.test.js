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
    const composable = readFileSync(resolve(__dirname, '../../composables/course-management/useMonthlyRenewal.js'), 'utf8');
    expect(composable).toContain('invalidateMonthlyRenewalPreview(form.value, endDate)');
    expect(composable).toContain('applyMonthlyRenewalPreview(form.value, json)');
    expect(source).toContain('monthlyRenewal.loadPreview(course, endDate)');
    expect(source).toContain(':warnings="renewMonthlyWarnings"');
    expect(source).toContain(':submitting="renewMonthlySubmitting"');
    expect(source.match(/end_date: c\.end_date \|\| \(c\.EndDate/g)?.length).toBe(2);
  });
});

import { batchRenewalEnd, nextPeriodEnd, nextRenewalMonth, renewalErrorMessage } from '../../lib/monthlyRenewalPreview';

describe('batch monthly renewal periods', () => {
  const today = new Date(2026, 9, 1);
  it('ends the next period on the settlement day', () => {
    expect(nextPeriodEnd('2026-09-30', 31, today)).toBe('2026-10-31');
    expect(nextPeriodEnd('2026-10-01', 31, today)).toBe('2026-10-31'); // 化學: 10-02..10-31, not 11-01
    expect(nextPeriodEnd('2026-09-09', 9, today)).toBe('2026-10-09');
    expect(nextPeriodEnd('2026-10-31', 31, today)).toBe('2026-11-30');
    expect(nextPeriodEnd('2026-12-31', 31, today)).toBe('2027-01-31');
    expect(nextPeriodEnd('2026-09-30', null, today)).toBe('2026-10-31');
  });

  it('renews one cycle when it starts in or before the chosen month', () => {
    expect(batchRenewalEnd('2026-09-30', 31, '2026-10', today)).toBe('2026-10-31');
    expect(batchRenewalEnd('2026-10-01', 31, '2026-10', today)).toBe('2026-10-31'); // was wrongly "covered"
    expect(batchRenewalEnd('2026-10-31', 31, '2026-10', today)).toBeNull();
    expect(nextRenewalMonth(['2026-10-31', '2026-09-30'], today)).toBe('2026-10');
    expect(nextRenewalMonth(['2026-10-31'], today)).toBe('2026-11');
  });

  it('prefers Laravel validation text over the generic English message', () => {
    expect(renewalErrorMessage({ message: 'The given data was invalid.', errors: { end_date: ['新的結束日必須晚於今天。'] } }, 'x')).toBe('新的結束日必須晚於今天。');
    expect(renewalErrorMessage({}, '續報失敗')).toBe('續報失敗');
  });

  it('students page offers one-dialog renewal and deep links monthly renew into it', () => {
    expect(source).toContain('data-testid="batch-monthly-renew"');
    expect(source).toContain('getRenewableMonthlyCourses(student.id).some((c) => c.id === targetCourse.id)) openBatchRenew(student)');
  });
});
