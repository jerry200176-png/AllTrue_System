import { describe, it, expect, vi, afterEach } from 'vitest';
import { ref, nextTick } from 'vue';
import { mount } from '@vue/test-utils';
import { ymdInTaipei, recentDaysRangeTaipei } from '../../lib/sessionDates.js';
import { useCalendarLeaveExtra } from '../../composables/calendar/useCalendarLeaveExtra.js';
import PaymentEntryModal from '../PaymentEntryModal.vue';

// 23:30Z on 11-06 is 07:30 on 11-07 in Taipei: the UTC day is "yesterday".
const NOW = '2026-11-06T23:30:00Z';
afterEach(() => vi.useRealTimers());

describe('"today" is the Taipei day, not the UTC day (#4991 P12)', () => {
  it('ymdInTaipei returns 11-07 while toISOString says 11-06', () => {
    vi.useFakeTimers(); vi.setSystemTime(new Date(NOW));
    // eslint-disable-next-line no-restricted-syntax -- demonstrates the old UTC-day behaviour this test guards against
    expect(new Date().toISOString().slice(0, 10)).toBe('2026-11-06');
    expect(ymdInTaipei()).toBe('2026-11-07');
    expect(ymdInTaipei(new Date('2026-11-07T15:59:59Z'))).toBe('2026-11-07');
    expect(ymdInTaipei(new Date('2026-11-07T16:00:00Z'))).toBe('2026-11-08');
  });

  it('PaymentEntryModal defaults payment_date to the Taipei day', async () => {
    vi.useFakeTimers({ toFake: ['Date'] }); vi.setSystemTime(new Date(NOW));
    global.fetch = vi.fn();
    const w = mount(PaymentEntryModal, { props: { show: false, row: { id: 5, student_name: 'A', subject: 'x', charge: 3000, payable_status: 'invoiced', payable_amount: 3000, payable_outstanding: 3000 } } });
    await w.setProps({ show: true }); await nextTick();
    expect(w.find('input[type="date"]').element.value).toBe('2026-11-07');
  });

  it('leave modal date defaults to the Taipei day', () => {
    vi.useFakeTimers(); vi.setSystemTime(new Date(NOW));
    const { openLeaveModal, leaveForm } = useCalendarLeaveExtra({
      supabase: {}, branchId: ref(1), showModal: ref(true),
      modalForm: ref({ student_id: 10, subject: 'Math', day_of_week: 3, start_time: '16:00', end_time: '18:00' }),
      editingCourseId: ref(1), contextMenu: ref({}), loadCourses: vi.fn(), getToken: vi.fn(),
      allStudents: ref([]), getSubjectLabel: (s) => s,
    });
    openLeaveModal();
    expect(leaveForm.value.schedule_date).toBe('2026-11-07');
  });

  it('recent 7 days range is Taipei on both ends (11-01..11-07)', () => {
    vi.useFakeTimers(); vi.setSystemTime(new Date(NOW));
    expect(recentDaysRangeTaipei(7)).toEqual({ start: '2026-11-01', end: '2026-11-07' });
  });
});
