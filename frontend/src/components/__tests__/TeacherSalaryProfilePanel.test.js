import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
vi.mock('../../lib/authedFetch', () => ({ getAccessToken: async () => 'test-only' }));
import Panel from '../TeacherSalaryProfilePanel.vue';
import InputPanel from '../TeacherEligibilityInputPanel.vue';
const teacher = { teacher_id: 701, teacher_name: '測試老師甲', settlement: { base_salary: 33000 } };
const reply = (data, status = 200) => ({ ok: status < 300, status, json: async () => data });
const pending = { id: 4, teacher_id: 701, branch_id: 15, base_salary: '0.00', effective_from: '2026-11-01', status: 'pending' };
const wrappers = [];
let fetcher;
const button = (w, text) => w.findAll('button').find(b => b.text() === text);
const open = async (props = {}) => {
  const w = mount(Panel, { props: { teachers: [teacher], branchId: 15, start: '2026-11-01', userRole: 'director', ...props },
    global: { stubs: { PinLockModal: { template: '<div data-testid="pin"><button @click="$emit(\'unlocked\')">解鎖</button><button @click="$emit(\'skip\')">略過</button></div>' } } } });
  wrappers.push(w); await w.get('#salary-teacher').setValue('701'); return w;
};
const fill = async (w, value = '0') => { await w.get('#salary-amount').setValue(value); await w.get('#salary-effective').setValue('2026-11-01'); };
const review = async w => { await button(w, '核對本薪更正').trigger('click'); await flushPromises(); };
beforeEach(() => { localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'unit-test-token' })); fetcher = vi.fn(async () => reply(pending, 201)); vi.stubGlobal('fetch', fetcher); });
afterEach(() => { wrappers.splice(0).forEach(w => w.unmount()); vi.unstubAllGlobals(); localStorage.clear(); });

describe('in-app #545 existing salary profile correction', () => {
  it('is reachable through the existing input panel without changing the payroll page', async () => {
    const w = mount(InputPanel, { props: { teachers: [teacher], branchId: 15, start: '2026-11-01', userRole: 'director' } });
    wrappers.push(w); await flushPromises(); expect(w.findComponent(Panel).exists()).toBe(true);
  });
  it('reuses current data, previews explicit zero with identity and date, then leaves director write pending', async () => {
    const w = await open(); expect(w.text()).toContain('33,000'); expect(fetcher).not.toHaveBeenCalled();
    await fill(w); await review(w); expect(fetcher).not.toHaveBeenCalled();
    expect(w.get('[data-testid="salary-confirm"]').text()).toContain('測試老師甲');
    expect(w.get('[data-testid="salary-confirm"]').text()).toContain('0 元');
    expect(w.get('[data-testid="salary-confirm"]').text()).toContain('所有校區的薪資試算共用');
    await button(w, '送總部核准').trigger('click'); await flushPromises();
    expect(JSON.parse(fetcher.mock.calls[0][1].body)).toEqual({ teacher_id: 701, branch_id: 15, base_salary: 0, effective_from: '2026-11-01' });
    expect(w.text()).toContain('尚未生效'); expect(w.emitted('changed')).toHaveLength(1);
    expect(teacher.settlement.base_salary).toBe(33000);
  });
  it('rejects blanks/negative values and invalidates confirmation when amount or campus changes', async () => {
    const w = await open();
    for (const v of ['', '-1']) { await fill(w, v); expect(button(w, '核對本薪更正').attributes('disabled')).toBeDefined(); }
    await fill(w); await review(w); await w.get('#salary-amount').setValue('100');
    expect(w.find('[data-testid="salary-confirm"]').exists()).toBe(false);
    await review(w); await w.setProps({ branchId: 16 }); expect(w.find('[data-testid="salary-confirm"]').exists()).toBe(false);
    expect(fetcher).not.toHaveBeenCalled();
  });
  it('requires an explicit headquarters acknowledgement for all-campus settings and reports actual approved status', async () => {
    const w = await open({ userRole: 'super_admin', branchId: null }); await fill(w);
    expect(button(w, '核對本薪更正').attributes('disabled')).toBeDefined();
    await w.get('#salary-global').setValue(true); await review(w);
    expect(w.get('[data-testid="salary-confirm"]').text()).toContain('所有校區');
    fetcher.mockResolvedValueOnce(reply({ ...pending, status: 'approved' }, 201));
    await button(w, '儲存並核准本薪').trigger('click'); await flushPromises();
    expect(JSON.parse(fetcher.mock.calls[0][1].body)).not.toHaveProperty('branch_id');
    expect(w.text()).toContain('已核准');
  });
  it('preserves pending zero and only HQ can explicitly confirm its approval', async () => {
    const t = { ...teacher, pending_salary: pending };
    const director = await open({ teachers: [t] }); expect(director.text()).toContain('待總部核准：0 元');
    expect(button(director, '核准這筆本薪')).toBeUndefined();
    const hq = await open({ teachers: [t], userRole: 'super_admin' });
    await button(hq, '核准這筆本薪').trigger('click'); expect(fetcher).not.toHaveBeenCalled();
    fetcher.mockResolvedValueOnce(reply({ ...pending, status: 'approved' }));
    await button(hq, '確認核准本薪').trigger('click'); await flushPromises();
    expect(fetcher.mock.calls[0][0]).toContain('/salary-profiles/4/approve');
    expect(hq.emitted('changed')).toHaveLength(1);
    const wrongBranch = await open({ teachers: [t], userRole: 'super_admin', branchId: 16 });
    expect(button(wrongBranch, '核准這筆本薪').attributes('disabled')).toBeDefined();
  });
  it('keeps PIN protection, never auto-submits on skip/unlock, and preserves lock refusal', async () => {
    const w = await open(); await fill(w); await review(w);
    fetcher.mockResolvedValueOnce(reply({ message: '請輸入 PIN', code: 'pin_required' }, 423));
    await button(w, '送總部核准').trigger('click'); await flushPromises();
    expect(w.get('[data-testid="pin"]').exists()).toBe(true); expect(w.emitted('changed')).toBeUndefined();
    await button(w, '解鎖').trigger('click'); await flushPromises(); expect(fetcher).toHaveBeenCalledTimes(1);
    await review(w); fetcher.mockResolvedValueOnce(reply({ message: '該月份已鎖定' }, 422));
    await button(w, '送總部核准').trigger('click'); await flushPromises();
    expect(w.text()).toContain('該月份已鎖定'); expect(w.emitted('changed')).toBeUndefined();
  });
  it('does not resend an uncertain result or claim success, and denies a director missing campus', async () => {
    const w = await open(); await fill(w); await review(w); fetcher.mockRejectedValueOnce(new Error('network'));
    await button(w, '送總部核准').trigger('click'); await flushPromises();
    expect(w.text()).toContain('尚未確認結果'); expect(w.emitted('changed')).toBeUndefined();
    expect(button(w, '核對本薪更正').attributes('disabled')).toBeDefined();
    const noCampus = await open({ branchId: null }); await fill(noCampus);
    expect(button(noCampus, '核對本薪更正').attributes('disabled')).toBeDefined();
    expect(fetcher).toHaveBeenCalledTimes(1);
  });
});
