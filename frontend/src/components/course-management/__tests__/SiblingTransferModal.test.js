import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const authedFetch = vi.fn();
vi.mock('../../../lib/authedFetch', () => ({ authedFetch: (...a) => authedFetch(...a) }));

import SiblingTransferModal from '../SiblingTransferModal.vue';

// In-app #412: 轉給手足. The server computes the amount; the dialog previews, then confirms with one key.
const students = [{ id: 1, name: '本人' }, { id: 2, name: '手足甲' }, { id: 3, name: '手足乙' }];
const plan = { remaining_sessions: 4, moved_sessions: 4, paid_amount: 12000, amount: 6000, source_keeps_amount: 6000, family_link: true };
const reply = (ok, json) => ({ ok, json: async () => json });
const open = async (props = {}) => {
  const wrapper = mount(SiblingTransferModal, { props: { show: false, courseId: 10, ownStudentId: 1, students, ...props } });
  await wrapper.setProps({ show: true });
  return wrapper;
};
const fill = async (w, { target = '2', reason = '手足接課' } = {}) => {
  await w.find('#sib-target').setValue(target);
  await w.find('#sib-reason').setValue(reason);
};
const sent = (n) => JSON.parse(authedFetch.mock.calls[n][1].body);
const button = (w, text) => w.findAll('button').find((b) => b.text() === text);

describe('SiblingTransferModal', () => {
  beforeEach(() => authedFetch.mockReset());

  it('offers the other students of the campus, never the course\'s own student', async () => {
    const w = await open();
    const names = w.findAll('#sib-target option').map((o) => o.text());
    expect(names).toEqual(['請選擇', '手足甲', '手足乙']);
  });

  it('cannot preview or confirm until a student and a reason are given, and confirm needs a preview', async () => {
    const w = await open();
    expect(button(w, '試算').attributes('disabled')).toBeDefined();
    await fill(w);
    expect(button(w, '試算').attributes('disabled')).toBeUndefined();
    expect(button(w, '確認轉給手足').attributes('disabled')).toBeDefined();
  });

  it('previews the server plan, then confirms with the same idempotency key and the same fields', async () => {
    authedFetch.mockResolvedValueOnce(reply(true, { preview: true, plan }));
    authedFetch.mockResolvedValueOnce(reply(true, { replayed: false, plan, transfer: { id: 5 } }));
    const w = await open();
    await fill(w);
    await w.find('#sib-sessions').setValue('3');
    await button(w, '試算').trigger('click');
    await flushPromises();
    expect(authedFetch.mock.calls[0][0]).toBe('/api/v1/student-classes/10/sibling-transfer');
    expect(sent(0)).toMatchObject({ target_student_id: 2, sessions: 3, reason: '手足接課', preview: true });
    expect(w.get('[data-testid="sibling-preview"]').text()).toContain('$6000');
    expect(w.find('[data-testid="sibling-ack"]').exists()).toBe(false);

    await button(w, '確認轉給手足').trigger('click');
    await flushPromises();
    expect(sent(1)).toMatchObject({ target_student_id: 2, sessions: 3, preview: false });
    expect(sent(1).idempotency_key).toBe(sent(0).idempotency_key);
    expect(sent(1).idempotency_key.length).toBeGreaterThanOrEqual(8);
    expect(w.emitted('done')[0][0].transfer.id).toBe(5);
  });

  it('asks for an explicit confirmation when the two students share no family record', async () => {
    authedFetch.mockResolvedValueOnce(reply(true, { preview: true, plan: { ...plan, family_link: false } }));
    authedFetch.mockResolvedValueOnce(reply(true, { replayed: false, transfer: { id: 6 } }));
    const w = await open();
    await fill(w);
    await button(w, '試算').trigger('click');
    await flushPromises();
    expect(w.find('[data-testid="sibling-ack"]').exists()).toBe(true);
    expect(button(w, '確認轉給手足').attributes('disabled')).toBeDefined();
    await w.find('[data-testid="sibling-ack"] input').setValue(true);
    await button(w, '確認轉給手足').trigger('click');
    await flushPromises();
    expect(sent(1).acknowledge_no_family_link).toBe(true);
  });

  it('shows the server refusal and invalidates the preview when the target changes', async () => {
    authedFetch.mockResolvedValueOnce(reply(true, { preview: true, plan }));
    authedFetch.mockResolvedValueOnce(reply(false, { message: '只能轉讓給同一分校的學生。', code: 'sibling_transfer_cross_campus' }));
    const w = await open();
    await fill(w);
    await button(w, '試算').trigger('click');
    await flushPromises();
    await button(w, '確認轉給手足').trigger('click');
    await flushPromises();
    expect(w.get('[role="alert"]').text()).toBe('只能轉讓給同一分校的學生。');
    expect(w.emitted('done')).toBeUndefined();
    await w.find('#sib-target').setValue('3');
    expect(w.find('[data-testid="sibling-preview"]').exists()).toBe(false);
  });

  it('uses a fresh key each time it is opened', async () => {
    authedFetch.mockResolvedValue(reply(true, { preview: true, plan }));
    const w = await open();
    await fill(w);
    await button(w, '試算').trigger('click');
    await flushPromises();
    await w.setProps({ show: false });
    await w.setProps({ show: true });
    await fill(w);
    await button(w, '試算').trigger('click');
    await flushPromises();
    expect(sent(1).idempotency_key).not.toBe(sent(0).idempotency_key);
  });
});
