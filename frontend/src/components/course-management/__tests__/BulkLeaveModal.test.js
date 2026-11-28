import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import BulkLeaveModal from '../BulkLeaveModal.vue';

describe('BulkLeaveModal skipped rows', () => {
  it('offers 改用續約順延 only on a row the server marked, and emits that row', async () => {
    const skipped = [
      { course_id: 1, session_date: '2026-11-24', reason: 'x', class_session_id: 7, renewal_shift: true },
      { course_id: 2, session_date: '2026-11-24', reason: 'y', class_session_id: 8, renewal_shift: false },
      { course_id: 3, session_date: '2026-11-24', reason: 'z', class_session_id: 9, renewal_shift: true, shifted: true },
    ];
    const w = mount(BulkLeaveModal, { props: { show: true, form: { start_date: '', end_date: '' }, result: { message: 'm', skipped } } });
    const buttons = w.findAll('[data-testid="bulk-leave-shift"]');
    expect(buttons).toHaveLength(1);
    await buttons[0].trigger('click');
    expect(w.emitted('shift')[0][0].class_session_id).toBe(7);
    expect(w.text()).toContain('已改用續約順延完成');
  });
});
