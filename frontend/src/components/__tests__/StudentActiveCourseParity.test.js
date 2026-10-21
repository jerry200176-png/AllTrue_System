import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { isStudentPageActiveCourse, isHiddenHistoricalCourse } from '../../lib/courseMoneyState.js';

// in-app #427: the 「已有進行中課程」 prompt (server EnrollmentActiveCourseRule) and the 學生管理 course list
// must count the same courses. Both sides read this one fixture; the PHPUnit side is
// backend/tests/Unit/EnrollmentActiveCourseRuleTest.php.
const __dirname = dirname(fileURLToPath(import.meta.url));
const fixture = JSON.parse(readFileSync(resolve(__dirname, '../../../../backend/tests/Fixtures/active_course_parity.json'), 'utf8'));
const source = readFileSync(resolve(__dirname, '../../pages/StudentsList.vue'), 'utf8');

describe('學生管理 進行中 course parity (in-app #427)', () => {
  const pageRows = fixture.rows.filter((row) => !row.server_only);

  it.each(pageRows.map((row) => [row.case, row]))('page list agrees with the prompt: %s', (_case, row) => {
    expect(isStudentPageActiveCourse(row) && row.status === 'active').toBe(row.expected_active);
  });

  it('covers closed, finished, stopped and package rows', () => {
    const cases = fixture.rows.map((row) => row.case).join('|');
    for (const needle of ['closed', 'finished', 'stopped', 'package']) expect(cases).toContain(needle);
  });

  it('keeps the page on the shared verdict and scopes the prompt to the page campus', () => {
    expect(source).toContain('!isHiddenHistoricalCourse(course)');
    expect(source).not.toContain('const isHistoricalCourse');
    expect(source).toContain('/active-courses${branchQuery}');
    expect(source).toContain('branch_id=${encodeURIComponent(props.branchId)}');
  });

  it('still hides a used-up settled course but keeps a used-up unpaid one', () => {
    const base = { status: 'active', payment_type: 'session', remaining_sessions: 0 };
    expect(isHiddenHistoricalCourse({ ...base, payment_status: 'paid' })).toBe(true);
    expect(isHiddenHistoricalCourse({ ...base, payment_status: 'unpaid' })).toBe(false);
  });
});
