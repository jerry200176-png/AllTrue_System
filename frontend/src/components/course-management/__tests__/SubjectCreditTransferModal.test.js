import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
const fetcher = vi.fn();
vi.mock('../../../lib/authedFetch', () => ({ authedFetch: (...a) => fetcher(...a) }));
import Modal from '../SubjectCreditTransferModal.vue';

const course = { id: 10, student_id: 1, student_name: '測試學生', subject: 'math', subject_name: '數學' };
const target = { id: 20, student_id: 1, student_name: '測試學生', subject: 'english', subject_name: '英文', teacher_name: '英文教師' };
const transfer = { id: 7, source_course_id: 10, target_course_id: 30, moved_sessions: 2, amount: 3000, reason: '跨科使用', reversed: false };
const plan = { preview: true, execution_available: true, state_hash: 'a'.repeat(64), plan: { purchased_sessions: 8, used_sessions: 2, reserved_sessions: 1, available_sessions: 5, moved_sessions: 2, unit_price: '1500', lesson_minutes: 120, amount: 3000, destination_existing_outstanding: 12000 } };
const response = (json, status = 200) => ({ ok: status < 300, status, json: async () => json });
const writes = [], wrappers = [];
let records;
const open = async (c = course) => {
  const w = mount(Modal, { attachTo: document.body, props: { show: false, course: c } }); wrappers.push(w);
  await w.setProps({ show: true }); await flushPromises(); return w;
};
const button = (w, text) => w.findAll('button').find(b => b.text() === text);
const fill = async w => {
  await w.get('#credit-target').setValue('20'); await w.get('#credit-sessions').setValue('2'); await w.get('#credit-reason').setValue('跨科使用');
};
const preview = async w => { await button(w, '試算轉用').trigger('click'); await flushPromises(); };
const bodies = () => fetcher.mock.calls.filter(([, i]) => i?.method === 'POST').map(([, i]) => JSON.parse(i.body));
beforeEach(() => {
  fetcher.mockReset(); writes.length = 0; records = [];
  fetcher.mockImplementation(async (url, init) => {
    if (init?.method === 'POST') { const next = writes.shift(); if (next instanceof Error) throw next; return next ?? response(plan); }
    if (url.includes('?student_id=')) return response({ data: url.includes('page=2') ? [target] : [course, { ...target, id: 99, student_id: 2 }], last_page: 2, total: 3 });
    return response({ data: records, current_page: 1, last_page: 1, total: records.length });
  });
});
afterEach(() => { wrappers.splice(0).forEach(w => w.unmount()); document.body.innerHTML = ''; });

describe('in-app #535 subject-credit workflow', () => {
  it('shows distinct student identity for otherwise identical contracts and blocks unnamed confirmation', async () => {
    for (const name of ['學生甲', '學生乙']) {
      const w = await open({ ...course, student_name: name }); await fill(w); await preview(w);
      expect(w.get('[data-testid="credit-student"]').text()).toContain(name);
      expect(w.get('[data-testid="credit-preview"]').text()).toContain(name);
      expect(button(w, '確認跨科轉用').attributes('disabled')).toBeUndefined();
      await w.setProps({ show: false });
    }
    records = [transfer];
    const unnamed = await open({ ...course, student_name: '  ' }); await fill(unnamed); await preview(unnamed);
    expect(button(unnamed, '確認跨科轉用').attributes('disabled')).toBeDefined();
    expect(button(unnamed, '還原這筆轉用').attributes('disabled')).toBeDefined();
    expect(unnamed.text()).toContain('學生資料未完整載入');
  });
  it('loads all bounded same-student pages and names the actual destination teacher', async () => {
    const w = await open();
    expect(fetcher.mock.calls.filter(([u]) => u.includes('?student_id=1'))).toHaveLength(2);
    expect(w.findAll('#credit-target option').map(o => o.text())).toEqual(['請選另一科合約', '英文 · 英文教師']);
    expect(w.text()).toContain('舊欠款不會抵銷'); expect(w.text()).toContain('尚無跨科轉用紀錄');
  });
  it('requires fresh eligible preview and invalidates it on reason/count change', async () => {
    const w = await open(); await fill(w);
    expect(button(w, '確認跨科轉用').attributes('disabled')).toBeDefined(); await preview(w);
    expect(w.get('[data-testid="credit-preview"]').text()).toContain('保留排課 1 堂'); expect(w.text()).toContain('12,000');
    await w.get('#credit-reason').setValue('另一原因'); expect(w.find('[data-testid="credit-preview"]').exists()).toBe(false);
    await preview(w); await w.get('#credit-sessions').setValue('3');
    expect(button(w, '確認跨科轉用').attributes('disabled')).toBeDefined();
    for (const n of ['0', '-1', '1.5']) { await w.get('#credit-sessions').setValue(n); expect(button(w, '試算轉用').attributes('disabled')).toBeDefined(); }
  });
  it('retries exactly the same intent/key after a lost response and reopening', async () => {
    const w = await open(); await fill(w); await preview(w); writes.push(new Error('lost response'));
    await button(w, '確認跨科轉用').trigger('click'); await flushPromises(); const original = bodies()[1];
    expect(w.get('#credit-target').attributes('disabled')).toBeDefined(); expect(w.text()).toContain('尚未確認結果');
    await w.setProps({ show: false }); await w.setProps({ show: true }); await flushPromises();
    writes.push(response({ transfer, replayed: true })); records = [transfer];
    await button(w, '重試確認結果').trigger('click'); await flushPromises(); expect(bodies()[2]).toEqual(original);
    expect(original).toMatchObject({ target_course_id: 20, sessions: 2, reason: '跨科使用', state_hash: plan.state_hash });
    expect(original.idempotency_key.length).toBeGreaterThanOrEqual(8); expect(w.emitted('changed')).toHaveLength(1);
    expect(w.text()).toContain('已確認轉用');
  });
  it('disables execution for unready schema and preserves server diagnostics', async () => {
    const w = await open(); await fill(w); writes.push(response({ ...plan, execution_available: false })); await preview(w);
    expect(button(w, '確認跨科轉用').attributes('disabled')).toBeDefined();
    writes.push(response({ message: '原單價或堂長不同，請先核對。' }, 422)); await preview(w);
    expect(w.get('[role="alert"]').text()).toContain('原單價或堂長不同'); expect(w.emitted('changed')).toBeUndefined();
  });
  it('asks separately to reverse from the new batch and respects later-activity refusal', async () => {
    records = [transfer]; const w = await open({ ...target, id: 30 }); await button(w, '還原這筆轉用').trigger('click');
    expect(w.get('[aria-label="還原確認"]').text()).toContain('測試學生');
    expect(bodies()).toHaveLength(0); await w.get('#credit-reverse-reason').setValue('排課前改回');
    writes.push(response({ message: '新批次已有排課，不能還原。' }, 409));
    await button(w, '確認還原').trigger('click'); await flushPromises();
    expect(fetcher.mock.calls.find(([u, i]) => u.endsWith('/7/reverse') && i?.method === 'POST')[0]).toBe('/api/v1/student-classes/10/subject-credit-transfer/7/reverse');
    expect(w.text()).toContain('新批次已有排課，不能還原'); expect(w.emitted('changed')).toBeUndefined();
  });
  it('keeps the exact reverse reason/path through an uncertain result and successful replay', async () => {
    records = [transfer]; const w = await open({ ...target, id: 30 });
    await button(w, '還原這筆轉用').trigger('click'); await w.get('#credit-reverse-reason').setValue('改回原科');
    writes.push(response({ message: '暫時無法回應' }, 503));
    await button(w, '確認還原').trigger('click'); await flushPromises();
    expect(w.get('#credit-reverse-reason').attributes('disabled')).toBeDefined();
    writes.push(response({ replayed: true, transfer: { ...transfer, reversed: true } }));
    await button(w, '重試確認結果').trigger('click'); await flushPromises();
    expect(bodies()[1]).toEqual(bodies()[0]); expect(w.text()).toContain('已確認還原');
    expect(w.emitted('changed')).toHaveLength(1);
  });
  it('does not turn a committed transfer into a retry when only history refresh fails', async () => {
    const w = await open(); await fill(w); await preview(w);
    writes.push(response({ transfer, replayed: false }, 201));
    fetcher.mockImplementation(async (url, init) => init?.method === 'POST' ? writes.shift() : response({ message: '無法讀取轉用紀錄' }, 503));
    await button(w, '確認跨科轉用').trigger('click'); await flushPromises();
    expect(w.text()).toContain('已確認轉用'); expect(w.text()).toContain('無法讀取轉用紀錄');
    expect(button(w, '重試確認結果')).toBeUndefined(); expect(w.emitted('changed')).toHaveLength(1);
    expect(button(w, '確認跨科轉用').attributes('disabled')).toBeDefined();
  });
  it('traps/restores focus and rejects partial candidate pagination', async () => {
    const opener = document.createElement('button'); document.body.appendChild(opener); opener.focus();
    const w = await open(); expect(document.activeElement.id).toBe('credit-target');
    [...w.element.querySelectorAll('button:not([disabled])')].pop().focus();
    await w.get('[role="dialog"]').trigger('keydown', { key: 'Tab' }); expect(document.activeElement.id).toBe('credit-target');
    await w.get('[role="dialog"]').trigger('keydown', { key: 'Escape' }); expect(w.emitted('close')).toHaveLength(1);
    await w.setProps({ show: false }); expect(document.activeElement).toBe(opener);
    fetcher.mockImplementation(async u => u.includes('?student_id=') ? response({ data: [target], last_page: 30, total: 3000 }) : response({ data: [] }));
    await w.setProps({ show: true }); await flushPromises(); expect(w.text()).toContain('無法完整載入'); expect(w.findAll('#credit-target option')).toHaveLength(1);
  });
});
