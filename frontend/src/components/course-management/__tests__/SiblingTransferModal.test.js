import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const authedFetch = vi.fn();
vi.mock('../../../lib/authedFetch', () => ({ authedFetch: (...a) => authedFetch(...a) }));

import SiblingTransferModal from '../SiblingTransferModal.vue';

// In-app #412: 轉給手足. The server computes the amount; the dialog previews, then confirms with one key.
const students = [{ id: 1, name: '本人' }, { id: 2, name: '手足甲' }, { id: 3, name: '手足乙' }];
const isStudentList = (url) => String(url).startsWith('/api/v1/students?');
const plan = { remaining_sessions: 4, moved_sessions: 4, paid_amount: 12000, amount: 6000, source_keeps_amount: 6000, family_link: true };
const reply = (ok, json) => ({ ok, json: async () => json });
const open = async (props = {}) => {
  const wrapper = mount(SiblingTransferModal, { attachTo: document.body, props: { show: false, courseId: 10, ownStudentId: 1, branchId: 7, ...props } });
  await wrapper.setProps({ show: true });
  await flushPromises();
  return wrapper;
};
const fill = async (w, { target = '2', reason = '手足接課' } = {}) => {
  await w.find('#sib-target').setValue(target);
  await w.find('#sib-reason').setValue(reason);
};
const transferCalls = () => authedFetch.mock.calls.filter(([url]) => !isStudentList(url));
const sent = (n) => JSON.parse(transferCalls()[n][1].body);
const button = (w, text) => w.findAll('button').find((b) => b.text() === text);

describe('SiblingTransferModal', () => {
  // Student list answers by URL; the transfer replies are queued per test (mockResolvedValueOnce).
  const queued = [];
  beforeEach(() => {
    authedFetch.mockReset();
    queued.length = 0;
    authedFetch.mockImplementation(async (url) => (isStudentList(url) ? reply(true, students) : (queued.shift() ?? reply(true, { preview: true, plan }))));
  });
  const queue = (...r) => queued.push(...r);
  afterEach(() => { document.body.innerHTML = ''; });

  it('offers the other students of the campus, never the course\'s own student', async () => {
    const w = await open();
    const names = w.findAll('#sib-target option').map((o) => o.text());
    expect(names).toEqual(['請選擇', '手足甲', '手足乙']);
  });

  it('loads every student of the campus (per_page=all), not just a first page', async () => {
    await open();
    const [url] = authedFetch.mock.calls[0];
    expect(url).toBe('/api/v1/students?branch_id=7&per_page=all');
  });

  it('rejects 0, negative and fractional counts instead of treating them as "all remaining"', async () => {
    const w = await open();
    await fill(w);
    for (const bad of ['0', '-2', '1.5']) {
      await w.find('#sib-sessions').setValue(bad);
      expect(button(w, '試算').attributes('disabled')).toBeDefined();
      expect(w.find('#sib-sessions-err').exists()).toBe(true);
    }
    await w.find('#sib-sessions').setValue('');
    expect(button(w, '試算').attributes('disabled')).toBeUndefined();
    await button(w, '試算').trigger('click');
    await flushPromises();
    expect(sent(0)).not.toHaveProperty('sessions'); // blank = all remaining, sent as omitted
  });

  it('moves focus into the dialog, keeps Tab inside, closes on Esc and gives focus back', async () => {
    const opener = document.createElement('button');
    document.body.appendChild(opener);
    opener.focus();
    const w = await open();
    expect(document.activeElement.id).toBe('sib-target');
    const last = [...w.element.querySelectorAll('button:not([disabled])')].pop();
    last.focus();
    await w.find('[role="dialog"]').trigger('keydown', { key: 'Tab' });
    expect(document.activeElement.id).toBe('sib-target'); // wrapped to the first control
    await w.find('[role="dialog"]').trigger('keydown', { key: 'Escape' });
    expect(w.emitted('close')).toHaveLength(1);
    await w.setProps({ show: false });
    expect(document.activeElement).toBe(opener);
  });

  it('cannot preview or confirm until a student and a reason are given, and confirm needs a preview', async () => {
    const w = await open();
    expect(button(w, '試算').attributes('disabled')).toBeDefined();
    await fill(w);
    expect(button(w, '試算').attributes('disabled')).toBeUndefined();
    expect(button(w, '確認轉給手足').attributes('disabled')).toBeDefined();
  });

  it('previews the server plan, then confirms with the same idempotency key and the same fields', async () => {
    queue(reply(true, { preview: true, plan }));
    queue(reply(true, { replayed: false, plan, transfer: { id: 5 } }));
    const w = await open();
    await fill(w);
    await w.find('#sib-sessions').setValue('3');
    await button(w, '試算').trigger('click');
    await flushPromises();
    expect(transferCalls()[0][0]).toBe('/api/v1/student-classes/10/sibling-transfer');
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
    queue(reply(true, { preview: true, plan: { ...plan, family_link: false } }));
    queue(reply(true, { replayed: false, transfer: { id: 6 } }));
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
    queue(reply(true, { preview: true, plan }));
    queue(reply(false, { message: '只能轉讓給同一分校的學生。', code: 'sibling_transfer_cross_campus' }));
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
