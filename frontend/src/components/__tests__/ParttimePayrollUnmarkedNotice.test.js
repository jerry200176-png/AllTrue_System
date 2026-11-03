import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const summary = {
  lock_status: 'draft', anomaly_count: 0, anomalies: [],
  unmarked_count: 2,
  unmarked_lessons: [
    { code: 'unmarked_attendance', message: '已結束但沒有點名，不會算進薪資', class_session_id: 11, session_date: '2026-10-31' },
    { code: 'unmarked_attendance', message: '已結束但沒有點名，不會算進薪資', class_session_id: 12, session_date: '2026-10-30' },
    { code: 'unmarked_attendance', message: '已結束但沒有點名，不會算進薪資', class_session_id: 47206, session_date: '2026-10-29', start_time: '14:00', end_time: '16:00', student_name: '王小明', subject_name: '數學' },
  ],
};

vi.mock('../../lib/parttimePayrollApi.js', () => ({
  fetchPayrollSummary: vi.fn(async () => ({ summary, teachers: [] })),
  fetchTeacherSessions: vi.fn(), exportPayrollCsv: vi.fn(), lockPayroll: vi.fn(), reopenPayroll: vi.fn(),
  fetchPayrollRules: vi.fn(async () => ({})), updatePayrollRules: vi.fn(),
  fetchTeacherPayrollRules: vi.fn(), updateTeacherPayrollRules: vi.fn(), deleteTeacherPayrollRules: vi.fn(),
}));

import ParttimePayrollPage from '../../pages/ParttimePayrollPage.vue';

describe('ParttimePayrollPage unmarked lessons notice', () => {
  it('shows an info notice with the real count and keeps the lock button enabled', async () => {
    const w = mount(ParttimePayrollPage, { props: { branchId: 1 } });
    await flushPromises();
    const notice = w.find('[data-testid="payroll-unmarked-notice"]');
    expect(notice.exists()).toBe(true);
    expect(notice.text()).toContain('有 2 堂已結束但沒有點名，不會算進薪資。請補點名。');
    expect(notice.text()).toContain('2026-10-31');
    // in-app #482: the row says who / what / when, not 堂次 #47206
    expect(notice.text()).toContain('14:00–16:00　王小明・數學');
    expect(notice.text()).not.toContain('堂次 #');
    expect(w.find('.payroll-anomaly-panel').exists()).toBe(false);
    const lock = w.find('button.btn-primary.small');
    expect(lock.exists()).toBe(true);
    expect(lock.attributes('disabled')).toBeUndefined();
  });
});
