import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
const ITEMS = [
  { type: 'attendance', id: 3, date: '2026-10-15', subject: '數學', attendance: '缺席' },
  { type: 'assessment', id: 2, date: '2026-10-08', subject: '英文', title: '單元檢測', score: 80, max_score: 100 },
  { type: 'evaluation', id: 1, date: '2026-10-01', subject: '數學', attendance: '到班', approval_status: 'approved', homework_status: '完成', next_week_scope: '第三章', progress: '完成第二單元', content: '分數應用題', has_substantive_progress: true },
  { type: 'exam_score', id: 8, date: '2026-09-30', subject: '數學', title: '期中考', score: 0, max_score: 100, is_absent: false },
  { type: 'exam_score', id: 9, date: '2026-09-20', subject: '英文', title: '第一次段考', score: null, max_score: 100, is_absent: true },
];
vi.mock('../../lib/authedFetch', () => ({ authedFetch: vi.fn() }));
vi.mock('../../lib/sessionDates', () => ({ ymdInTaipei: () => '2026-11-10' }));
import { authedFetch } from '../../lib/authedFetch';
import StudentLearningTimeline from '../students/StudentLearningTimeline.vue';
const response = body => ({ ok: true, json: async () => body });
const wrappers = [];
const profile = () => { const w = mount(StudentLearningTimeline, { props: { show: true, studentId: 1, studentName: '合成學生' }, global: { stubs: { DialogContent: { template: '<section><slot /></section>' } } } }); wrappers.push(w); return w; };
const tab = (w, text) => w.findAll('[role="tab"]').find(x => x.text() === text);
const activate = async (w, text) => { await tab(w, text).trigger('mousedown', { button: 0 }); await flushPromises(); };
beforeEach(() => {
  authedFetch.mockReset(); authedFetch.mockImplementation(async url => response(url.endsWith('/exam-periods')
    ? { data: [{ id: 1, label: '第二次段考', start_date: '2026-11-20', end_date: '2026-11-21' }] }
    : { items: ITEMS, coverage: { limit_per_source: 300, capped_sources: [] } }));
});
afterEach(() => { wrappers.splice(0).forEach(w => w.unmount()); });
describe('student learning profile (#4970 step3a)', () => {
  it('shows real progress and school dates, then filters class history', async () => {
    const w = profile(); await flushPromises();
    expect(w.text()).toContain('學習檔案'); expect(w.text()).toContain('完成第二單元'); expect(w.text()).toContain('第二次段考');
    expect(w.text()).not.toContain('學習計畫'); await activate(w, '上課紀錄');
    expect(w.findAll('[data-testid="timeline-row"]').map(r => r.text().slice(0, 10))).toEqual(['2026-10-15', '2026-10-01']);
    expect(w.text()).toContain('分數應用題'); await w.get('[aria-label="結束日期"]').setValue('2026-10-02');
    expect(w.findAll('[data-testid="timeline-row"]')).toHaveLength(1);
    await w.get('[data-testid="timeline-subject"]').setValue('英文'); expect(w.text()).toContain('尚無');
  });
  it('distinguishes zero points from absence and shows both score sources', async () => {
    const w = profile(); await flushPromises(); await activate(w, '成績');
    expect(w.text()).toContain('0/100'); expect(w.text()).toContain('缺考'); expect(w.text()).toContain('段考'); expect(w.text()).toContain('單元檢測');
    expect(w.findAll('[data-testid="timeline-row"]')).toHaveLength(3);
  });
  it('keeps the latest system placeholder in history without replacing older real progress', async () => {
    authedFetch.mockImplementation(async url => response(url.endsWith('/exam-periods') ? { data: [] } : { items: [
      { type: 'evaluation', id: 20, date: '2026-10-20', content: '（系統自動核准）', approval_status: 'approved', has_substantive_progress: false }, ...ITEMS,
    ] }));
    const w = profile(); await flushPromises(); expect(w.text()).toContain('完成第二單元'); expect(w.text()).not.toContain('（系統自動核准）');
    await activate(w, '上課紀錄'); expect(w.text()).toContain('（系統自動核准）');
  });
  it('distinguishes submitted and reviewed assessments without giving school scores review badges', async () => {
    authedFetch.mockImplementation(async url => response(url.endsWith('/exam-periods') ? { data: [] } : { items: [
      { ...ITEMS[1], title: '待核單元', approval_status: 'submitted' },
      { ...ITEMS[1], id: 12, title: '已核單元', approval_status: 'reviewed' }, ITEMS[3],
    ] }));
    const w = profile(); await flushPromises(); await activate(w, '成績');
    const rows = w.findAll('[data-testid="timeline-row"]'); expect(rows[0].text()).toContain('已送出'); expect(rows[1].text()).toContain('已覆核');
    expect(rows[2].text()).not.toMatch(/已送出|已覆核/);
  });
  it('keeps class history usable when school-date reading fails and explains the cap', async () => {
    authedFetch.mockImplementation(async url => url.endsWith('/exam-periods') ? { ok: false } : response({ items: ITEMS, coverage: { limit_per_source: 300, capped_sources: ['evaluation'] } }));
    const w = profile(); await flushPromises(); expect(w.text()).toContain('校考日期暫時無法載入'); expect(w.text()).toContain('300');
    await activate(w, '上課紀錄'); expect(w.text()).toContain('完成第二單元');
  });
  it('ignores superseded requests and stops before school dates when the primary read is refused', async () => {
    let resolveA; authedFetch.mockImplementationOnce(() => new Promise(r => { resolveA = r; }));
    const w = profile(); await w.setProps({ studentId: 2 }); await flushPromises();
    resolveA(response({ items: [{ type: 'attendance', id: 99, date: '2020-01-01' }] })); await flushPromises(); expect(w.text()).not.toContain('2020-01-01');
    authedFetch.mockReset(); authedFetch.mockResolvedValue({ ok: false }); await w.setProps({ studentId: 3 }); await flushPromises();
    expect(w.text()).toContain('載入失敗'); expect(authedFetch).toHaveBeenCalledTimes(1);
  });
});
