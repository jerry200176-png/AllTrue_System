import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import axe from 'axe-core';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import AssessmentPage from '../../pages/AssessmentPage.vue';

// #4989: 學習檢測 on components/ui + app-overlay, mounted with fetch and the session mocked.
// Every request, payload and campus scope below is the one the page sent before the migration.
beforeAll(() => {
  globalThis.ResizeObserver ||= class { observe() {} unobserve() {} disconnect() {} };
  Element.prototype.hasPointerCapture ||= () => false;
  Element.prototype.setPointerCapture ||= () => {};
  Element.prototype.releasePointerCapture ||= () => {};
  Element.prototype.scrollIntoView ||= () => {};
});

const source = readFileSync(resolve(__dirname, '../../pages/AssessmentPage.vue'), 'utf8');
const legacyList = JSON.parse(readFileSync(resolve(__dirname, '../../../../scripts/ui-legacy-pages.json'), 'utf8'));
const json = (body, ok = true) => ({ ok, json: async () => body });
const tick = async () => { await flushPromises(); await nextTick(); await new Promise((r) => setTimeout(r, 0)); await flushPromises(); };
const q = (s) => document.body.querySelector(s);
const button = (root, text) => [...root.querySelectorAll('button')].find((b) => b.textContent.trim() === text);

const assessmentRows = () => [
  { id: 101, title: '測試草稿檢測', assessment_type: 'baseline', status: 'draft', max_score: 100, student_name: null, scheduled_for: null, result_count: 0 },
  { id: 102, title: '測試已發布檢測', assessment_type: 'checkpoint', status: 'published', max_score: 100, student_name: '測試學生甲', scheduled_for: '2026-09-08', result_count: 2 },
];
const summary = { assessment_count: 2, result_count: 5, average_percent: 81.5, reviewed_count: 3, remediation_open_count: 4, remediation_overdue_count: 1 };
const results = [
  { id: 701, student_id: 201, student_name: '測試學生甲', attempt_no: 1, score: 82, max_score: 100, percent: 82, status: 'submitted', remediation_count: 1 },
  { id: 702, student_id: 202, student_name: '測試學生乙', attempt_no: 2, score: 90, max_score: 100, percent: 90, status: 'reviewed', remediation_count: 0 },
];
const students = [{ student_class_id: 501, student_id: 201, name: '測試學生甲 · 數學' }];
const questionRowsPublished = [{ id: 601, question_bank_item_id: 31, question_type: 'short_answer', prompt: '測試簡答題', knowledge_tag: '過去式', difficulty: 2, position: 1 }];
const attemptRows = [{ id: 801, status: 'submitted', student_id: 201, student_name: '測試學生甲', attempt_no: 1, score: null, max_score: 100 }];
const attemptDetail = { id: 801, status: 'submitted', student_name: '測試學生甲', attempt_no: 1, questions: [], answers: [{ id: 901, status: 'needs_review', max_score: 10, prompt: '測試簡答題', position: 1, answer: '測試答案' }] };

let wrapper;
let fetchMock;
let confirmSpy;
const calls = () => fetchMock.mock.calls.map(([url, init]) => ({ url: String(url).replace('/api/v1', ''), method: init?.method || 'GET', body: init?.body, headers: init?.headers }));
const writes = () => calls().filter((c) => c.method !== 'GET');
const reads = () => calls().filter((c) => c.method === 'GET');
const bodyOf = (c) => JSON.parse(c.body);

function defaultRoute(path, method) {
  if (method !== 'GET') return json({ data: { id: 999 } });
  if (path.startsWith('/assessments?')) return json({ data: assessmentRows() });
  if (path.startsWith('/assessment-reports/summary')) return json({ data: summary });
  if (path.startsWith('/assessment-options/classes')) return json({ data: [{ id: 501, student_name: '測試學生甲', subject: '數學' }] });
  if (path === '/assessments/102/results') return json({ data: results });
  if (path === '/assessments/101/results') return json({ data: [] });
  if (path.endsWith('/students')) return json({ data: students });
  if (path.endsWith('/questions')) return json({ data: path.includes('/102/') ? questionRowsPublished : [] });
  if (path === '/assessments/102/attempts') return json({ data: attemptRows });
  if (path === '/assessment-attempts/801') return json({ data: attemptDetail });
  if (path.startsWith('/question-banks?')) return json({ data: [{ id: 21, name: '測試題庫' }] });
  if (path === '/question-banks/21/items?status=approved') return json({ data: [
    { id: 31, question_key: 'k1', version_no: 1, prompt: '舊版題目', knowledge_tag: '過去式', difficulty: 2 },
    { id: 32, question_key: 'k1', version_no: 2, prompt: '新版題目', knowledge_tag: '過去式', difficulty: 3 },
    { id: 33, question_key: 'k2', version_no: 1, prompt: '另一題', knowledge_tag: '單字', difficulty: 1 },
  ] });
  if (path === '/assessment-results/701/remediation-actions') return json({ data: [{ id: 401, knowledge_tag: '過去式', plan: '每天十題', due_date: '2026-09-30', status: 'open' }] });
  return json({ data: [] });
}
const mountPage = async (props = {}, route = null) => {
  fetchMock = vi.fn(async (input, init = {}) => {
    const path = String(input).replace('/api/v1', '');
    return route?.(path, init.method || 'GET', init) || defaultRoute(path, init.method || 'GET');
  });
  vi.stubGlobal('fetch', fetchMock);
  wrapper = mount(AssessmentPage, { props: { branchId: 1, userRole: 'director', ...props }, attachTo: document.body });
  await tick();
  return wrapper;
};
const setValue = async (selector, value) => {
  const el = q(selector);
  el.value = value;
  el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true }));
  await tick();
};
const click = async (el) => { el.click(); await tick(); };
const openDetail = async (w, title = '測試已發布檢測') => {
  const row = w.findAll('.assessment-desktop-table tbody tr').find((r) => r.text().includes(title));
  await row.find('button').trigger('click');
  await tick();
};
const submitForm = async () => { q('[role=dialog] form').dispatchEvent(new Event('submit', { cancelable: true, bubbles: true })); await tick(); };

beforeEach(() => {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'unit-test-token' }));
  confirmSpy = vi.fn(() => true);
  vi.stubGlobal('confirm', confirmSpy);
});
afterEach(() => { wrapper?.unmount(); wrapper = null; vi.unstubAllGlobals(); localStorage.clear(); document.body.innerHTML = ''; });

describe('AssessmentPage on the template', () => {
  it('loads the three list reads of the branch with the session token; one h2, no main, guide anchor kept', async () => {
    const w = await mountPage();
    expect(reads().map((c) => c.url).sort()).toEqual([
      '/assessment-options/classes?campus_id=1',
      '/assessment-reports/summary?campus_id=1',
      '/assessments?campus_id=1&per_page=100',
    ]);
    expect(calls().every((c) => c.headers.Authorization === 'Bearer unit-test-token' && c.headers.Accept === 'application/json')).toBe(true);
    expect(writes()).toHaveLength(0);
    expect(w.findAll('h2').map((h) => h.text())).toEqual(['學習檢測']);
    expect(w.find('h1').exists()).toBe(false);
    expect(w.findAll('main, [role="main"]')).toHaveLength(0);
    expect(w.element.tagName).toBe('DIV');
    expect(w.find('[data-testid="assessment-page"]').exists()).toBe(true);
    expect(w.findAll('[data-guide="assessment-header"]')).toHaveLength(1);
    expect(w.find('[data-guide="assessment-header"] h2').exists()).toBe(true);
    expect(w.text()).toContain('建立檢測、記錄學生多次結果，與課後評量分開管理。');
  });

  it('shows the six summary numbers with their existing labels, and a dash for no average', async () => {
    const w = await mountPage();
    const tiles = w.findAll('[data-testid="assessment-summary"] [data-slot="card"]').map((c) => c.text().replace(/\s+/g, ''));
    expect(tiles).toEqual(['檢測數2', '結果筆數5', '平均分數81.5%', '已審核結果3', '待補強4', '逾期補強1']);
    w.unmount();
    const none = await mountPage({}, (path) => (path.startsWith('/assessment-reports/summary') ? json({ data: { ...summary, average_percent: null } }) : null));
    expect(none.findAll('[data-testid="assessment-summary"] [data-slot="card"]')[2].text().replace(/\s+/g, '')).toBe('平均分數—');
  });

  it('keeps the table semantics, the phone cards and one named action per row', async () => {
    const w = await mountPage();
    expect(w.findAll('thead th').map((th) => [th.text(), th.attributes('scope')])).toEqual([
      ['檢測', 'col'], ['範圍', 'col'], ['日期', 'col'], ['狀態', 'col'], ['結果', 'col'], ['操作', 'col'],
    ]);
    const rows = w.findAll('.assessment-desktop-table tbody tr');
    expect(rows.map((tr) => tr.findAll('td').slice(0, 5).map((td) => td.text()))).toEqual([
      ['測試草稿檢測baseline', '分校共用', '未設定', '草稿', '0'],
      ['測試已發布檢測checkpoint', '測試學生甲', '2026-09-08', '已發布', '2'],
    ]);
    expect(rows[0].findAll('button').map((b) => [b.text(), b.attributes('aria-label'), b.attributes('type')])).toEqual([
      ['結果', '查看結果：測試草稿檢測', 'button'], ['發布', '發布檢測：測試草稿檢測', 'button'],
    ]);
    expect(rows[1].findAll('button').map((b) => [b.text(), b.attributes('aria-label')])).toEqual([
      ['結果', '查看結果：測試已發布檢測'], ['關閉', '關閉檢測：測試已發布檢測'],
    ]);
    expect(w.find('.assessment-mobile-list').attributes('aria-label')).toBe('檢測清單');
    const cards = w.findAll('.assessment-mobile-card');
    expect(cards).toHaveLength(2);
    expect(cards[1].findAll('dd').map((d) => d.text())).toEqual(['測試學生甲', '2026-09-08', '2 筆']);
    expect(cards[1].findAll('button').map((b) => b.text())).toEqual(['查看結果', '關閉']);
    // Table and cards swap at exactly one width: Tailwind 4 `max-[641px]` is width < 641 (up to 640), `min-[641px]` is 641 and up.
    expect(w.find('.assessment-desktop-table').classes()).toContain('tw:max-[641px]:hidden');
    expect(w.find('.assessment-mobile-list').classes()).toContain('tw:min-[641px]:hidden');
    expect(w.findAll('button').every((b) => b.attributes('type') === 'button')).toBe(true);
    expect(await axe.run(w.element, { rules: { 'color-contrast': { enabled: false } } }).then((r) => r.violations.map((v) => v.id))).toEqual([]);
  });

  it('announces loading, shows the empty state, and the error alert re-reads on 重試 and moves focus to 重新整理', async () => {
    const held = [];
    fetchMock = vi.fn(() => new Promise((r) => { held.push(r); }));
    vi.stubGlobal('fetch', fetchMock);
    wrapper = mount(AssessmentPage, { props: { branchId: 1, userRole: 'director' }, attachTo: document.body });
    await tick();
    const loading = wrapper.find('[data-testid="assessment-loading"]');
    expect([loading.attributes('role'), loading.attributes('aria-live'), loading.text()]).toEqual(['status', 'polite', '載入中']);
    expect(wrapper.find('[data-testid="assessment-content"]').attributes('aria-busy')).toBe('true');
    held.forEach((r) => r(json({ data: [] })));
    await tick();
    expect(wrapper.find('[data-testid="assessment-content"]').attributes('aria-busy')).toBe('false');
    expect(wrapper.find('[data-testid="assessment-empty"]').text()).toContain('目前還沒有檢測');
    expect(wrapper.find('[data-testid="assessment-empty"]').text()).toContain('先建立一份基準檢測，再發布後登錄學生結果。');
    wrapper.unmount();

    let down = true;
    const w = await mountPage({}, (path) => (down && path.startsWith('/assessments?') ? json({ message: '檢測資料暫時無法載入' }, false) : null));
    const alert = w.find('[role="alert"]');
    expect(alert.find('[data-slot="alert-title"]').text()).toBe('無法載入檢測');
    expect(alert.text()).toContain('檢測資料暫時無法載入');
    expect(w.find('table').exists()).toBe(false);
    down = false;
    const before = fetchMock.mock.calls.length;
    const retry = alert.find('button');
    retry.element.focus();
    await retry.trigger('click');
    await tick();
    expect(fetchMock.mock.calls.length).toBe(before + 3);
    expect(w.findAll('.assessment-desktop-table tbody tr')).toHaveLength(2);
    // 重試 left the screen with the error; focus went to 重新整理 before the reload instead of the document.
    expect(document.activeElement).toBe(w.find('[data-testid="assessment-refresh"]').element);
  });

  it('no branch means no request; a new branch reads again with that branch', async () => {
    const none = await mountPage({ branchId: null });
    expect(fetchMock).not.toHaveBeenCalled();
    none.unmount();
    const w = await mountPage();
    fetchMock.mockClear();
    await w.setProps({ branchId: 7 });
    await tick();
    expect(reads().map((c) => c.url).sort()).toEqual([
      '/assessment-options/classes?campus_id=7',
      '/assessment-reports/summary?campus_id=7',
      '/assessments?campus_id=7&per_page=100',
    ]);
  });

  it('desktop and mobile 發布 send the same POST without a body; a refusal replaces the list as before', async () => {
    const w = await mountPage();
    const publishes = w.findAll('button').filter((b) => b.text() === '發布');
    expect(publishes).toHaveLength(2);
    for (const b of publishes) { await b.trigger('click'); await tick(); }
    expect(writes().map((c) => [c.url, c.method, c.body])).toEqual([
      ['/assessments/101/publish', 'POST', undefined], ['/assessments/101/publish', 'POST', undefined],
    ]);
    w.unmount();
    const refused = await mountPage({}, (path, method) => (method === 'POST' ? json({ message: '測試無法發布' }, false) : null));
    await refused.findAll('button').find((b) => b.text() === '發布').trigger('click');
    await tick();
    expect(refused.find('[role="alert"]').text()).toContain('測試無法發布');
  });

  it('關閉 is director-only and asks through ConfirmDialog (no window.confirm); 取消 sends nothing, 確認 sends one POST and re-reads', async () => {
    const teacher = await mountPage({ userRole: 'teacher' });
    expect(teacher.findAll('button').filter((b) => b.text() === '關閉')).toHaveLength(0);
    teacher.unmount();

    const w = await mountPage();
    const closes = w.findAll('button').filter((b) => b.text() === '關閉');
    expect(closes).toHaveLength(2);
    await closes[0].trigger('click');
    await tick();
    expect(q('[role=alertdialog]').textContent).toContain('關閉後不能再修改檢測定義，確定要關閉嗎？');
    q('[data-testid="confirm-dialog-cancel"]').click();
    await tick();
    expect(q('[role=alertdialog]')).toBeNull();
    expect(writes()).toHaveLength(0);

    await closes[1].trigger('click');
    await tick();
    const readsBefore = reads().length;
    q('[data-testid="confirm-dialog-confirm"]').click();
    q('[data-testid="confirm-dialog-confirm"]')?.click();
    await tick();
    expect(writes().map((c) => [c.url, c.method, c.body])).toEqual([['/assessments/102/close', 'POST', undefined]]);
    expect(q('[role=alertdialog]')).toBeNull();
    expect(reads().length).toBe(readsBefore + 3);
    expect(confirmSpy).not.toHaveBeenCalled();
  });

  it('create dialog: labelled fields with the existing wording, first field focused, payload and campus unchanged, one request', async () => {
    const w = await mountPage();
    await w.find('[data-guide="assessment-header"] button').trigger('click');
    await tick();
    const dialog = q('[role=dialog]');
    expect(dialog.querySelector('[data-slot="dialog-title"]').textContent).toBe('建立學習檢測');
    expect(dialog.textContent).toContain('先建立檢測定義，再發布後登錄學生結果。');
    for (const [id, label] of [['assessment-title', '檢測名稱'], ['assessment-course', '課程範圍'], ['assessment-type', '類型'], ['assessment-max', '滿分'], ['assessment-date', '檢測日期'], ['assessment-description', '說明']]) {
      expect(dialog.querySelector(`label[for="${id}"]`).textContent).toContain(label);
    }
    expect(q('#assessment-course').options[0].textContent).toBe('分校共用檢測');
    expect([...q('#assessment-course').options].map((o) => o.textContent)).toContain('測試學生甲 · 數學');
    expect([...q('#assessment-type').options].map((o) => o.textContent)).toEqual(['基準', '階段', '補強後', '其他']);
    expect([q('#assessment-title').getAttribute('maxlength'), q('#assessment-title').getAttribute('placeholder'), q('#assessment-description').getAttribute('maxlength')]).toEqual(['120', '例如：英文單字基準檢測', '10000']);
    expect(document.activeElement).toBe(q('#assessment-title'));
    expect(await axe.run(dialog, { rules: { 'color-contrast': { enabled: false } } }).then((r) => r.violations.map((v) => v.id))).toEqual([]);

    await setValue('#assessment-title', '  期中檢測  ');
    await setValue('#assessment-course', '501');
    await setValue('#assessment-type', 'baseline');
    await setValue('#assessment-max', '90.5');
    await setValue('#assessment-date', '2026-10-01');
    await setValue('#assessment-description', '  範圍：第一章  ');
    let release;
    fetchMock.mockImplementationOnce(() => new Promise((r) => { release = r; }));
    const form = q('[role=dialog] form');
    form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    await tick();
    const save = q('[role=dialog] button[type="submit"]');
    expect([save.textContent, save.disabled, save.getAttribute('aria-busy')]).toEqual(['建立中…', true, 'true']);
    expect(writes()).toHaveLength(1);
    expect(writes()[0].url).toBe('/assessments');
    expect(bodyOf(writes()[0])).toEqual({ campus_id: 1, title: '期中檢測', description: '範圍：第一章', assessment_type: 'baseline', max_score: 90.5, scheduled_for: '2026-10-01', student_class_id: 501 });
    expect(writes()[0].headers['Content-Type']).toBe('application/json');
    release(json({ data: { id: 103 } }));
    await tick();
    expect(q('[role=dialog]')).toBeNull();
    expect(reads().filter((c) => c.url.startsWith('/assessments?'))).toHaveLength(2);
  });

  it('create dialog: an empty or spaces-only name shows the reason next to the field and sends nothing; defaults, teacher wording, server refusal', async () => {
    const w = await mountPage({ userRole: 'teacher' });
    await w.find('[data-guide="assessment-header"] button').trigger('click');
    await tick();
    expect(q('#assessment-course').options[0].textContent).toBe('老師必須選擇課程');
    expect([q('#assessment-type').value, q('#assessment-max').value, q('#assessment-date').value]).toEqual(['checkpoint', '100', '']);
    // No native constraint on the field that shows an inline reason.
    expect([q('#assessment-title').required, q('#assessment-max').min]).toEqual([false, '']);
    await submitForm();
    const error = q('#assessment-title-error');
    expect([error.getAttribute('role'), error.textContent]).toEqual(['alert', '請輸入檢測名稱']);
    expect(q('#assessment-title').getAttribute('aria-describedby')).toBe(error.id);
    expect(q('#assessment-title').getAttribute('aria-invalid')).toBe('true');
    expect(document.activeElement).toBe(q('#assessment-title'));
    await setValue('#assessment-title', '   ');
    expect(q('#assessment-title-error')).toBeNull();
    await submitForm();
    expect(q('#assessment-title-error').textContent).toBe('請輸入檢測名稱');
    expect(writes()).toHaveLength(0);

    fetchMock.mockClear();
    await setValue('#assessment-title', '測試檢測');
    fetchMock.mockResolvedValueOnce(json({ message: '測試課程不可為空' }, false));
    await submitForm();
    expect(bodyOf(writes()[0]).student_class_id).toBeNull();
    expect(q('[role=dialog] [role="alert"]').textContent).toBe('測試課程不可為空');
    expect(q('[role=dialog]')).not.toBeNull();
    expect(q('[role=dialog] button[type="submit"]').disabled).toBe(false);
    // Reopening starts clean.
    q('[role=dialog]').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
    await tick();
    expect(q('[role=dialog]')).toBeNull();
    await w.find('[data-guide="assessment-header"] button').trigger('click');
    await tick();
    expect([q('#assessment-title').value, q('[role=dialog] [role="alert"]')]).toEqual(['', null]);
  });
});

describe('AssessmentPage detail sheet', () => {
  it('opens a published assessment with the four existing reads and shows its results', async () => {
    const w = await mountPage();
    fetchMock.mockClear();
    await openDetail(w);
    expect(reads().map((c) => c.url)).toEqual(['/assessments/102/results', '/assessments/102/students', '/assessments/102/questions', '/assessments/102/attempts']);
    const sheet = q('[data-slot="sheet-content"]');
    expect(sheet.getAttribute('role')).toBe('dialog');
    expect(sheet.querySelector('[data-slot="sheet-title"]').textContent).toBe('測試已發布檢測');
    expect(sheet.textContent).toContain('滿分 100 · 已發布');
    const list = sheet.querySelector('[aria-label="檢測結果清單"]');
    expect([...list.querySelectorAll('li')].map((li) => li.textContent.replace(/\s+/g, ' ').trim())).toEqual([
      expect.stringContaining('測試學生甲'), expect.stringContaining('測試學生乙'),
    ]);
    expect(list.textContent).toContain('第 1 次');
    expect(list.textContent).toContain('82/100（82%）');
    expect(list.textContent).toContain('待審');
    expect(list.textContent).toContain('已審核');
    expect(sheet.querySelector('.question-builder')).toBeNull();
    expect(await axe.run(sheet, { rules: { 'color-contrast': { enabled: false } } }).then((r) => r.violations.map((v) => v.id))).toEqual([]);
    sheet.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
    await tick();
    expect(q('[data-slot="sheet-content"]')).toBeNull();
  });

  it('a draft reads its question catalog (latest approved version per question) and configures the picked ids', async () => {
    const w = await mountPage();
    fetchMock.mockClear();
    await openDetail(w, '測試草稿檢測');
    expect(reads().map((c) => c.url)).toEqual([
      '/assessments/101/results', '/assessments/101/students', '/assessments/101/questions',
      '/question-banks?campus_id=1', '/question-banks/21/items?status=approved',
    ]);
    const builder = q('.question-builder');
    expect(builder.textContent).toContain('只可選擇已核准題目；發布後會固定題目版本。');
    const rows = [...builder.querySelectorAll('label')];
    expect(rows).toHaveLength(2);
    expect(rows[0].querySelector('strong').textContent).toBe('新版題目');
    expect(rows[0].querySelector('small').textContent).toBe('測試題庫 · 過去式 · 難度 3');
    expect([rows[1].querySelector('strong').textContent, rows[1].querySelector('small').textContent]).toEqual(['另一題', '測試題庫 · 單字 · 難度 1']);
    const configure = () => button(builder, '配置 0 題') || [...builder.querySelectorAll('button')].find((b) => b.textContent.includes('配置'));
    expect(configure().disabled).toBe(true);
    const boxes = [...builder.querySelectorAll('[role="checkbox"]')];
    expect(boxes).toHaveLength(2);
    await click(boxes[1]);
    await click(boxes[0]);
    expect(configure().textContent.trim()).toBe('配置 2 題');
    fetchMock.mockClear();
    await click(configure());
    expect(writes().map((c) => [c.url, c.method])).toEqual([['/assessments/101/questions', 'POST']]);
    expect(bodyOf(writes()[0])).toEqual({ question_bank_item_ids: [33, 32] });
  });

  it('result entry keeps the exact student/course payload and shows a rejected write next to the form', async () => {
    const w = await mountPage();
    await openDetail(w);
    const entry = q('.result-entry');
    expect(entry.querySelector('label[for="assessment-result-student"]').textContent).toContain('學生／課程');
    expect(button(entry, '儲存結果').disabled).toBe(true);
    await setValue('#assessment-result-student', '501');
    await setValue('#assessment-result-score', '82');
    await setValue('#assessment-result-notes', '  測試備註  ');
    fetchMock.mockClear();
    fetchMock.mockImplementationOnce(async () => json({ message: '測試寫入被拒' }, false));
    await click(button(entry, '儲存結果'));
    expect(writes().map((c) => [c.url, c.method])).toEqual([['/assessments/102/results', 'POST']]);
    expect(bodyOf(writes()[0])).toEqual({ student_id: 201, student_class_id: 501, score: 82, notes: '測試備註' });
    expect(q('.result-entry [role="alert"]').textContent).toBe('測試寫入被拒');
  });

  it('a successful result save re-reads the detail and the list', async () => {
    const w = await mountPage();
    await openDetail(w);
    await setValue('#assessment-result-student', '501');
    await setValue('#assessment-result-score', '0');
    fetchMock.mockClear();
    await click(button(q('.result-entry'), '儲存結果'));
    expect(bodyOf(writes()[0])).toEqual({ student_id: 201, student_class_id: 501, score: 0, notes: null });
    expect(reads().map((c) => c.url).filter((u) => u.startsWith('/assessments/102/')).length).toBe(4);
    expect(reads().filter((c) => c.url.startsWith('/assessments?'))).toHaveLength(1);
  });

  it('only a director reviews a submitted result, with the same POST', async () => {
    const w = await mountPage();
    await openDetail(w);
    const list = q('[aria-label="檢測結果清單"]');
    const reviews = [...list.querySelectorAll('button')].filter((b) => b.textContent.trim() === '審核');
    expect(reviews).toHaveLength(1);
    expect(reviews[0].getAttribute('aria-label')).toBe('審核結果：測試學生甲 第 1 次');
    fetchMock.mockClear();
    await click(reviews[0]);
    expect(writes().map((c) => [c.url, c.method, c.body])).toEqual([['/assessment-results/701/review', 'POST', undefined]]);
    w.unmount();
    const teacher = await mountPage({ userRole: 'teacher' });
    await openDetail(teacher);
    expect([...q('[aria-label="檢測結果清單"]').querySelectorAll('button')].filter((b) => b.textContent.trim() === '審核')).toHaveLength(0);
  });

  it('remediation: opens one result, creates with the exact payload, advances a status with PATCH', async () => {
    const w = await mountPage();
    await openDetail(w);
    fetchMock.mockClear();
    await click([...q('[aria-label="檢測結果清單"]').querySelectorAll('button')].find((b) => b.textContent.trim() === '1 筆'));
    expect(reads().map((c) => c.url)).toEqual(['/assessment-results/701/remediation-actions']);
    const panel = q('.remediation-panel');
    expect(panel.textContent).toContain('補強追蹤：測試學生甲');
    expect(panel.textContent).toContain('每天十題 · 到期 2026-09-30');
    expect(panel.textContent).toContain('待處理');
    expect(button(panel, '建立補強行動').disabled).toBe(true);
    await setValue('#assessment-remediation-tag', ' 英文／過去式 ');
    await setValue('#assessment-remediation-type', 'retake');
    await setValue('#assessment-remediation-due', '2026-10-15');
    await setValue('#assessment-remediation-plan', ' 重測一次 ');
    fetchMock.mockClear();
    await click(button(panel, '建立補強行動'));
    expect(writes().map((c) => [c.url, c.method])).toEqual([['/assessment-results/701/remediation-actions', 'POST']]);
    expect(bodyOf(writes()[0])).toEqual({ knowledge_tag: '英文／過去式', action_type: 'retake', plan: '重測一次', due_date: '2026-10-15' });
    fetchMock.mockClear();
    await click(button(q('.remediation-panel'), '開始'));
    expect(writes().map((c) => [c.url, c.method, c.body])).toEqual([['/assessment-remediation-actions/401', 'PATCH', JSON.stringify({ status: 'in_progress' })]]);
    await click(button(q('.remediation-panel'), '收合'));
    expect(q('.remediation-panel')).toBeNull();
  });

  it('attempts: start sends the exact student/course scope and shows a refusal', async () => {
    const w = await mountPage();
    await openDetail(w);
    const panel = q('.attempt-panel');
    expect(panel.textContent).toContain('教職員代學生開啟作答；客觀題自動評分，簡答題送主任複核。');
    expect(button(panel, '開始一次作答').disabled).toBe(true);
    await setValue('#assessment-attempt-student', '501');
    fetchMock.mockClear();
    fetchMock.mockImplementationOnce(async () => json({ message: '測試作答被拒' }, false));
    await click(button(panel, '開始一次作答'));
    expect(writes().map((c) => [c.url, c.method])).toEqual([['/assessments/102/attempts', 'POST']]);
    expect(bodyOf(writes()[0])).toEqual({ student_id: 201, student_class_id: 501 });
    expect(q('.attempt-panel [role="alert"]').textContent).toBe('測試作答被拒');
  });

  it('manual review keeps the answer identity and score payload, and shows a failure', async () => {
    const w = await mountPage();
    await openDetail(w);
    await click([...q('.attempt-panel').querySelectorAll('button')].find((b) => b.textContent.trim() === '複核'));
    expect(reads().map((c) => c.url)).toContain('/assessment-attempts/801');
    expect(q('.review-editor').textContent).toContain('學生答案：測試答案');
    await setValue('.review-row input', '7');
    fetchMock.mockClear();
    fetchMock.mockImplementationOnce(async () => json({ message: '測試複核被拒' }, false));
    await click(button(q('.review-editor'), '完成人工複核'));
    expect(writes().map((c) => [c.url, c.method])).toEqual([['/assessment-attempts/801/review', 'POST']]);
    expect(bodyOf(writes()[0])).toEqual({ reviews: [{ answer_id: 901, score: 7, review_note: null }] });
    expect(q('.attempt-panel [role="alert"]').textContent).toBe('測試複核被拒');
  });

  it('answering an in-progress attempt saves a draft and submits with the same two requests', async () => {
    const inProgress = {
      id: 802, status: 'in_progress', student_name: '測試學生甲', attempt_no: 2,
      questions: [
        { id: 611, position: 1, prompt: '單選題', question_type: 'single_choice', choices: ['甲', '乙'], knowledge_tag: 't', difficulty: 1 },
        { id: 612, position: 2, prompt: '複選題', question_type: 'multiple_choice', choices: ['丙', '丁'], knowledge_tag: 't', difficulty: 1 },
        { id: 613, position: 3, prompt: '簡答題', question_type: 'short_answer', knowledge_tag: 't', difficulty: 1 },
      ],
      // A multiple-choice answer starts as an empty list (a missing answer would make the checkbox model a boolean, as before).
      answers: [{ id: 950, question_id: 612, status: 'pending', answer: [] }],
    };
    const w = await mountPage({}, (path, method) => {
      if (path === '/assessments/102/attempts' && method === 'GET') return json({ data: [{ id: 802, status: 'in_progress', student_name: '測試學生甲', attempt_no: 2, score: null, max_score: 100 }] });
      if (path === '/assessment-attempts/802' && method === 'GET') return json({ data: inProgress });
      if (path === '/assessment-attempts/802/answers') return json({ data: inProgress });
      if (path === '/assessment-attempts/802/submit') return json({ data: { ...inProgress, status: 'reviewed', score: 5, max_score: 10, percent: 50 } });
      return null;
    });
    await openDetail(w);
    await click([...q('.attempt-panel').querySelectorAll('button')].find((b) => b.textContent.trim() === '檢視'));
    const editor = q('.attempt-editor');
    expect(editor.querySelectorAll('input[type="radio"]')).toHaveLength(2);
    expect([...editor.querySelectorAll('input[type="radio"]')].every((r) => r.name === 'q-611')).toBe(true);
    editor.querySelectorAll('input[type="radio"]')[1].click();
    await click(editor.querySelectorAll('input[type="checkbox"]')[0]);
    await click(editor.querySelectorAll('input[type="checkbox"]')[1]);
    await setValue('.attempt-editor textarea', '測試答案');
    fetchMock.mockClear();
    await click(button(editor, '送出作答'));
    expect(writes().map((c) => [c.url, c.method])).toEqual([
      ['/assessment-attempts/802/answers', 'PATCH'], ['/assessment-attempts/802/submit', 'POST'],
    ]);
    expect(bodyOf(writes()[0])).toEqual({ answers: [
      { question_id: 611, answer: '乙' }, { question_id: 612, answer: ['丙', '丁'] }, { question_id: 613, answer: '測試答案' },
    ] });
    fetchMock.mockClear();
    expect(reads().map((c) => c.url)).toEqual([]);
  });

  it('a failed detail read on a draft is shown at the top of the sheet', async () => {
    const w = await mountPage({}, (path) => (path === '/assessments/101/results' ? json({ message: '測試讀取失敗' }, false) : null));
    await openDetail(w, '測試草稿檢測');
    expect(q('[data-slot="sheet-content"] [role="alert"]').textContent).toBe('測試讀取失敗');
  });
});

describe('AssessmentPage parts', () => {
  it('has no legacy parts, scoped style or window.confirm left and is off the legacy list', () => {
    expect(source).not.toMatch(/design-system\/At|<style|material-symbols|style="|confirm\(/);
    expect(legacyList).not.toContain('src/pages/AssessmentPage.vue');
  });
});
