import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import axe from 'axe-core';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import BindingConflictReviewPage from '../../pages/BindingConflictReviewPage.vue';

// #4989: 衝突審查 on components/ui + app-overlay ConfirmDialog, mounted with fetch mocked (fake data only).
beforeAll(() => {
  globalThis.ResizeObserver ||= class { observe() {} unobserve() {} disconnect() {} };
  Element.prototype.hasPointerCapture ||= () => false;
  Element.prototype.setPointerCapture ||= () => {};
  Element.prototype.releasePointerCapture ||= () => {};
  Element.prototype.scrollIntoView ||= () => {};
});

// Shape of BindingConflictController::index.
const conflicts = () => [
  {
    id: 7, student_id: 7, student_name: '測試學生甲', campus_count: 2,
    bindings: [
      { id: 11, campus_id: 1, campus_name: '測試分校甲', line_user_id_masked: 'U1111111…aaaa', bound_at: '2026-10-01T12:00:00+08:00' },
      { id: 12, campus_id: 2, campus_name: '測試分校乙', line_user_id_masked: 'U2222222…bbbb', bound_at: '2026-10-02T12:00:00+08:00' },
    ],
  },
  {
    id: 8, student_id: 8, student_name: '', campus_count: 1,
    bindings: [
      { id: 21, campus_id: 1, campus_name: '測試分校甲', line_user_id_masked: '', bound_at: '' },
      { id: 22, campus_id: 1, campus_name: '測試分校甲', line_user_id_masked: 'U4444444…dddd', bound_at: '2026-10-03T12:00:00+08:00' },
    ],
  },
];
const json = (body, status = 200) => ({ ok: status < 400, status, json: async () => body });
const tick = async () => { await flushPromises(); await nextTick(); await new Promise((r) => setTimeout(r, 0)); await flushPromises(); };
const q = (s) => document.body.querySelector(s);
const source = readFileSync(resolve(__dirname, '../../pages/BindingConflictReviewPage.vue'), 'utf8');
const legacyList = JSON.parse(readFileSync(resolve(__dirname, '../../../../scripts/ui-legacy-pages.json'), 'utf8'));

let wrapper;
let fetchMock;
const writes = () => fetchMock.mock.calls.filter(([, init]) => init?.method && init.method !== 'GET');
const reads = () => fetchMock.mock.calls.filter(([, init]) => !init?.method).map(([url]) => url);
const mountPage = async (props = {}) => {
  wrapper = mount(BindingConflictReviewPage, { props: { userRole: 'super_admin', ...props }, attachTo: document.body });
  await tick();
  return wrapper;
};
const noAxeViolations = async (el) => expect(await axe.run(el, { rules: { 'color-contrast': { enabled: false } } }).then((r) => r.violations.map((v) => v.id))).toEqual([]);

beforeEach(() => {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'unit-test-token' }));
  fetchMock = vi.fn(async (url, init = {}) => ((init.method || 'GET') === 'GET' ? json({ conflicts: conflicts() }) : json({ message: 'ok', result: {} })));
  vi.stubGlobal('fetch', fetchMock);
});
afterEach(() => { wrapper?.unmount(); wrapper = null; vi.unstubAllGlobals(); localStorage.clear(); });

describe('BindingConflictReviewPage on the template', () => {
  it('a director reads only the own campus; the title is the only h2 and no main is added', async () => {
    const w = await mountPage({ userRole: 'director', branchId: 1 });
    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe('/api/v1/bindings/conflicts?campus_id=1');
    expect(init.headers.Authorization).toBe('Bearer unit-test-token');
    expect(w.findAll('h2').map((h) => h.text())).toEqual(['衝突審查']);
    expect(w.find('h1').exists()).toBe(false);
    expect(w.text()).toContain('同一學生綁定多個 LINE 帳號時在此審查，保留正確的那一側。');
    expect(w.find('[data-testid="binding-conflicts-count"]').text().replace(/\s+/g, ' ')).toBe('待審衝突 2 筆');
    // The page never had a main landmark; it must not add a second one inside a shell that may own it.
    expect(w.element.tagName).toBe('DIV');
    expect(w.findAll('main, [role="main"]')).toHaveLength(0);
  });

  it('the campus filter keeps its label and scoping: locked for a director, free for a super admin', async () => {
    const director = await mountPage({ userRole: 'director', branchId: 1 });
    expect(director.find('[role="group"]').attributes('aria-label')).toBe('衝突篩選');
    expect(director.find('label[for="bcrp-campus"]').text()).toBe('分校');
    const locked = director.find('#bcrp-campus');
    expect(locked.element.tagName).toBe('SELECT');
    expect(locked.element.disabled).toBe(true);
    expect(locked.findAll('option')[0].text()).toBe('全部（限權限）');
    director.unmount();

    fetchMock.mockClear();
    const admin = await mountPage({ userRole: 'super_admin', branchId: 1 });
    // A super admin starts on all campuses (no campus_id), as before.
    expect(reads()).toEqual(['/api/v1/bindings/conflicts']);
    const select = admin.find('#bcrp-campus');
    expect(select.element.disabled).toBe(false);
    await select.setValue('2');
    await tick();
    expect(reads()).toEqual(['/api/v1/bindings/conflicts', '/api/v1/bindings/conflicts?campus_id=2']);
    // A new branch from the shell reloads with the chosen filter still applied.
    await admin.setProps({ branchId: 3 });
    await tick();
    expect(reads()).toHaveLength(3);
    expect(reads()[2]).toBe('/api/v1/bindings/conflicts?campus_id=2');
  });

  it('keeps table semantics, a phone list with the same data, and named actions for every side', async () => {
    const w = await mountPage();
    expect(w.find('caption').text()).toBe('待審綁定衝突清單');
    expect(w.findAll('thead th').map((th) => [th.text(), th.attributes('scope')])).toEqual([
      ['學生', 'col'], ['兩方 LINE ID', 'col'], ['分校', 'col'], ['綁定時間', 'col'], ['操作', 'col'],
    ]);
    const rows = w.findAll('tbody tr');
    expect(rows).toHaveLength(2);
    const cells = (tr) => tr.findAll('td').map((td) => td.text().replace(/\s+/g, ' '));
    expect(cells(rows[0])[0]).toBe('測試學生甲');
    expect(cells(rows[0])[2]).toBe('測試分校甲、測試分校乙');
    expect(cells(rows[0])[3]).toBe('2026-10-01');
    // No name falls back to the student id; no LINE id and no date fall back to a dash (as before).
    expect(cells(rows[1])[0]).toBe('#8');
    expect(cells(rows[1])[2]).toBe('測試分校甲');
    expect(cells(rows[1])[3]).toBe('—');
    expect(rows[0].findAll('.bcrp-line-chip code').map((c) => c.text())).toEqual(['U1111111…aaaa', 'U2222222…bbbb']);
    expect(rows[1].findAll('.bcrp-line-chip code').map((c) => c.text())).toEqual(['—', 'U4444444…dddd']);
    expect(rows[0].findAll('button').map((b) => [b.text(), b.attributes('aria-label')])).toEqual([
      ['保留此側', '保留此側：測試學生甲 U1111111…aaaa'],
      ['保留此側', '保留此側：測試學生甲 U2222222…bbbb'],
      ['選擇保留側', '選擇保留側：測試學生甲'],
    ]);
    expect(rows[1].findAll('button').map((b) => b.attributes('aria-label'))).toEqual([
      '保留此側：#8 此帳號', '保留此側：#8 U4444444…dddd', '選擇保留側：#8',
    ]);

    expect(w.find('.bcrp-mobile-list').attributes('aria-label')).toBe('待審綁定衝突清單');
    const cards = w.findAll('.bcrp-mobile-card');
    expect(cards).toHaveLength(2);
    expect(cards[0].find('strong').text()).toBe('測試學生甲');
    expect(cards[0].findAll('dd').map((d) => d.text())).toEqual(['測試分校甲、測試分校乙', '2026-10-01']);
    expect(cards[0].findAll('button').map((b) => b.attributes('aria-label'))).toEqual([
      '保留此側：測試學生甲 U1111111…aaaa', '保留此側：測試學生甲 U2222222…bbbb', '選擇保留側：測試學生甲',
    ]);
    await noAxeViolations(w.element);
  });

  it('announces loading, shows the empty state, and the error alert refetches on 重試', async () => {
    let release;
    fetchMock.mockImplementationOnce(() => new Promise((r) => { release = r; }));
    const w = await mountPage();
    const loading = w.find('[data-testid="binding-conflicts-loading"]');
    expect([loading.attributes('role'), loading.attributes('aria-live'), loading.text()]).toEqual(['status', 'polite', '載入中']);
    expect(w.find('[data-testid="binding-conflicts-content"]').attributes('aria-busy')).toBe('true');
    release(json({ conflicts: [] }));
    await tick();
    expect(w.find('[data-testid="binding-conflicts-content"]').attributes('aria-busy')).toBe('false');
    const empty = w.find('[data-testid="binding-conflicts-empty"]');
    expect(empty.find('h3').text()).toBe('無待審衝突');
    expect(empty.text()).toContain('目前沒有同一學生綁定多個 LINE 帳號的衝突案件。');
    expect(w.find('[data-testid="binding-conflicts-count"]').text().replace(/\s+/g, ' ')).toBe('待審衝突 0 筆');
    expect(w.find('table').exists()).toBe(false);
    await noAxeViolations(w.element);
    w.unmount();

    let down = true;
    fetchMock.mockReset();
    fetchMock.mockImplementation(async () => (down ? json({ message: '測試：伺服器忙碌中' }, 503) : json({ conflicts: conflicts() })));
    const failed = await mountPage();
    const alert = failed.find('[role="alert"]');
    expect(alert.find('[data-slot="alert-title"]').text()).toBe('無法載入衝突清單');
    expect(alert.text()).toContain('測試：伺服器忙碌中');
    expect(failed.find('table').exists()).toBe(false);
    await noAxeViolations(failed.element);
    down = false;
    await alert.find('button').trigger('click');
    await tick();
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(failed.findAll('tbody tr')).toHaveLength(2);
  });

  it('a 404 or 500 answer keeps its own wording, and 重新整理 reads again', async () => {
    fetchMock.mockImplementationOnce(async () => json({}, 404));
    const w = await mountPage();
    expect(w.find('[role="alert"]').text()).toContain('後端衝突 API 尚未上線（HTTP 404）。此頁依賴 W31 P2-2 端點，合併後即可使用。');
    const refresh = w.findAll('button').find((b) => b.text() === '重新整理');
    await refresh.trigger('click');
    await tick();
    expect(reads()).toEqual(['/api/v1/bindings/conflicts', '/api/v1/bindings/conflicts']);
    expect(w.findAll('tbody tr')).toHaveLength(2);
  });

  it('super admin: the confirm asks the same question, 取消 sends nothing, 確認保留 posts keep once and reloads', async () => {
    const w = await mountPage();
    await w.findAll('tbody tr')[0].findAll('button')[1].trigger('click');
    await tick();
    const dialog = q('[role=alertdialog]');
    expect(dialog.querySelector('[data-slot="alert-dialog-title"]').textContent).toBe('保留綁定並解除另一側');
    expect(q('[data-testid="confirm-dialog-message"]').textContent).toBe('學生 測試學生甲 綁定了 2 個 LINE 帳號。確認保留 U2222222…bbbb，並解除其他綁定嗎？');
    expect(dialog.getAttribute('aria-describedby')).toBe(q('[data-testid="confirm-dialog-message"]').id);
    expect(q('[data-testid="confirm-dialog-confirm"]').textContent).toBe('確認保留');
    expect(document.activeElement).toBe(q('[data-testid="confirm-dialog-cancel"]'));
    await noAxeViolations(dialog);
    q('[data-testid="confirm-dialog-cancel"]').click();
    await tick();
    expect(q('[role=alertdialog]')).toBeNull();
    expect(writes()).toHaveLength(0);

    // The row button opens the confirm for the first side (as before).
    await w.findAll('tbody tr')[0].findAll('button')[2].trigger('click');
    await tick();
    expect(q('[data-testid="confirm-dialog-message"]').textContent).toContain('確認保留 U1111111…aaaa，');
    let release;
    fetchMock.mockImplementationOnce(() => new Promise((r) => { release = r; }));
    q('[data-testid="confirm-dialog-confirm"]').click();
    q('[data-testid="confirm-dialog-confirm"]').click();
    await tick();
    // While the request runs the dialog stays, both buttons are disabled, and a second click sends nothing.
    expect(q('[role=alertdialog]')).not.toBeNull();
    expect([q('[data-testid="confirm-dialog-confirm"]').disabled, q('[data-testid="confirm-dialog-cancel"]').disabled]).toEqual([true, true]);
    expect(writes()).toHaveLength(1);
    const [url, init] = writes()[0];
    expect([url, init.method]).toEqual(['/api/v1/bindings/conflicts/7/resolve', 'POST']);
    expect(init.headers.Authorization).toBe('Bearer unit-test-token');
    expect(JSON.parse(init.body)).toEqual({ action: 'keep', binding_id: 11 });
    const loadsBefore = reads().length;
    release(json({ message: 'ok', result: {} }));
    await tick();
    expect(q('[role=alertdialog]')).toBeNull();
    expect(reads()).toHaveLength(loadsBefore + 1);
  });

  it('director: the wording is 解除, the request is delete for the chosen side', async () => {
    const w = await mountPage({ userRole: 'director', branchId: 1 });
    expect(w.findAll('tbody tr')[0].findAll('button').map((b) => [b.text(), b.attributes('aria-label')])).toEqual([
      ['解除此綁定', '解除此綁定：測試學生甲 U1111111…aaaa'],
      ['解除此綁定', '解除此綁定：測試學生甲 U2222222…bbbb'],
      ['選擇解除側', '選擇解除側：測試學生甲'],
    ]);
    await w.findAll('tbody tr')[1].findAll('button')[0].trigger('click');
    await tick();
    expect(q('[data-slot="alert-dialog-title"]').textContent).toBe('解除此綁定');
    // No student name and no LINE id: the same fallbacks as before (#id, 此帳號).
    expect(q('[data-testid="confirm-dialog-message"]').textContent).toBe('學生 #8 綁定了 2 個 LINE 帳號。確認解除 此帳號 的綁定嗎？');
    expect(q('[data-testid="confirm-dialog-confirm"]').textContent).toBe('確認解除');
    q('[data-testid="confirm-dialog-confirm"]').click();
    await tick();
    expect(writes().map(([url, init]) => [url, JSON.parse(init.body)])).toEqual([
      ['/api/v1/bindings/conflicts/8/resolve', { action: 'delete', binding_id: 21 }],
    ]);
    expect(q('[role=alertdialog]')).toBeNull();
  });

  it('a refused resolve keeps the confirm open with the reason, does not reload, and can be retried', async () => {
    const w = await mountPage();
    await w.findAll('tbody tr')[0].findAll('button')[0].trigger('click');
    await tick();
    fetchMock.mockResolvedValueOnce(json({ message: '測試：此衝突已由他人處理' }, 422));
    const loadsBefore = reads().length;
    q('[data-testid="confirm-dialog-confirm"]').click();
    await tick();
    expect(q('[role=alertdialog]')).not.toBeNull();
    expect(q('[data-testid="confirm-dialog-message"]').textContent).toBe('學生 測試學生甲 綁定了 2 個 LINE 帳號。確認保留 U1111111…aaaa，並解除其他綁定嗎？\n\n測試：此衝突已由他人處理');
    expect(q('[data-testid="confirm-dialog-confirm"]').disabled).toBe(false);
    expect(reads()).toHaveLength(loadsBefore);

    // Escape closes it; opening again starts without the old reason.
    q('[role=alertdialog]').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
    await tick();
    expect(q('[role=alertdialog]')).toBeNull();
    await w.findAll('tbody tr')[0].findAll('button')[0].trigger('click');
    await tick();
    expect(q('[data-testid="confirm-dialog-message"]').textContent).not.toContain('測試：此衝突已由他人處理');

    // A network failure without a message uses the page's own sentence; the retry then succeeds.
    fetchMock.mockRejectedValueOnce({});
    q('[data-testid="confirm-dialog-confirm"]').click();
    await tick();
    expect(q('[data-testid="confirm-dialog-message"]').textContent).toContain('\n\n解決衝突失敗，請稍後再試');
    q('[data-testid="confirm-dialog-confirm"]').click();
    await tick();
    expect(writes()).toHaveLength(3);
    expect(q('[role=alertdialog]')).toBeNull();
    expect(reads()).toHaveLength(loadsBefore + 1);
  });

  it('has no legacy parts, scoped style or inline style left and is off the legacy list', () => {
    expect(source).not.toMatch(/design-system\/At|<style|material-symbols|style="/);
    expect(legacyList).not.toContain('src/pages/BindingConflictReviewPage.vue');
  });
});
