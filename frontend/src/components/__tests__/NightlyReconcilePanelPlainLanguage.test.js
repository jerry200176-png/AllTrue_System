import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

vi.mock('../../api', () => ({ getReconcileLatest: vi.fn(), getReconcileRows: vi.fn() }));

import * as api from '../../api';
import NightlyReconcilePanel from '../../pages/NightlyReconcilePanel.vue';
import { CATEGORY_GUIDE } from '../../composables/useNightlyReconcile';

const __dirname = dirname(fileURLToPath(import.meta.url));

function row(id, category, diff) {
  return {
    student_class_id: id, student_name: `學生${id}`, subject_name: '數學', campus_name: '東湖',
    session_count: 20, recorded_used: 5, expected_used: 5 + diff, actual_attended: 5, diff, category,
  };
}

async function mountWith(report) {
  api.getReconcileLatest.mockResolvedValue(report);
  const wrapper = mount(NightlyReconcilePanel, { props: { token: 't' } });
  await flushPromises();
  return wrapper;
}

describe('NightlyReconcilePanel plain-language summary (#3900)', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-10-13T09:00:00'));
  });
  afterEach(() => {
    vi.useRealTimers();
    vi.clearAllMocks();
  });

  it('says last night had no mismatches and shows no next-step list when the count is 0', async () => {
    const wrapper = await mountWith({
      checked_at: '2026-10-13 02:00:05', threshold: 0, total_checked: 812, mismatch_count: 0, cause_counts: {}, mismatches: [],
    });
    const verdict = wrapper.get('[data-testid="nr-verdict"]');
    expect(verdict.text()).toContain('昨晚核對無異常');
    expect(verdict.text()).toContain('核對 812 門');
    expect(wrapper.find('[data-testid="nr-guide"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="nr-stale"]').exists()).toBe(false);
  });

  it('summarises the count and gives each reported mismatch type its meaning and next step', async () => {
    const wrapper = await mountWith({
      checked_at: '2026-10-13 02:00:05', threshold: 0, total_checked: 812, mismatch_count: 3,
      cause_counts: { contract_cap: 2, source_conflict: 1 },
      mismatches: [row(11, 'contract_cap', 2), row(12, 'contract_cap', 1), row(13, 'source_conflict', 1)],
    });
    expect(wrapper.get('[data-testid="nr-verdict"]').text()).toContain('3 門課程需要確認');
    const items = wrapper.findAll('.nr-guide-item');
    expect(items.map((item) => item.attributes('data-category'))).toEqual(['contract_cap', 'source_conflict']);
    expect(items[0].text()).toContain('2 門');
    expect(items[0].text()).toContain(CATEGORY_GUIDE.contract_cap.meaning);
    expect(items[0].text()).toContain(CATEGORY_GUIDE.contract_cap.next);
    expect(items[1].text()).toContain(CATEGORY_GUIDE.source_conflict.next);
    // The course id is shown so the viewer can hand the exact row to engineering.
    const cells = wrapper.findAll('.nr-table tbody tr').map((tr) => tr.findAll('td').map((td) => td.text()));
    expect(cells.map((c) => c[3]).sort()).toEqual(['11', '12', '13']);
  });

  it('falls back to detail rows when an older report has no cause_counts, and to the unknown guide', async () => {
    const wrapper = await mountWith({
      checked_at: '2026-10-13 02:00:05', threshold: 0, total_checked: 5, mismatch_count: 1,
      mismatches: [row(21, 'something_new', 2)],
    });
    expect(wrapper.get('.nr-guide-item').text()).toContain(CATEGORY_GUIDE.unknown.next);
    expect(wrapper.get('.nr-guide-item h3').text()).toContain('待分類');
    expect(wrapper.get('.nr-guide-item h3').text()).not.toContain('something_new');
  });

  it('warns when no report was written since the latest 02:00 run', async () => {
    const wrapper = await mountWith({
      checked_at: '2026-10-10 02:00:05', threshold: 0, total_checked: 5, mismatch_count: 0, cause_counts: {}, mismatches: [],
    });
    expect(wrapper.get('[data-testid="nr-stale"]').text()).toContain('通知工程團隊');
    // A stale report must not claim to be last night's.
    expect(wrapper.get('[data-testid="nr-verdict"]').text()).toContain('最近一次核對無異常');
    expect(wrapper.get('[data-testid="nr-verdict"]').text()).not.toContain('昨晚');
  });

  it('turns stale while the tab stays open once today\'s 02:00 run is overdue (#3945)', async () => {
    vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] });
    vi.setSystemTime(new Date('2026-10-13T02:59:00+08:00'));
    const wrapper = await mountWith({
      checked_at: '2026-10-12 02:00:05', mode: 'live', threshold: 0, total_checked: 5, mismatch_count: 0, cause_counts: {}, mismatches: [],
    });
    expect(wrapper.find('[data-testid="nr-stale"]').exists()).toBe(false);
    vi.advanceTimersByTime(2 * 60 * 1000);
    await flushPromises();
    expect(wrapper.find('[data-testid="nr-stale"]').exists()).toBe(true);
    expect(wrapper.get('[data-testid="nr-verdict"]').text()).not.toContain('昨晚');
    wrapper.unmount();
  });

  it('reads the offset-less server timestamp as Taipei time, not viewer-local time', async () => {
    // 02:00:05 Taipei on the 13th is after the 02:00 run that is due by 09:00 Taipei.
    vi.setSystemTime(new Date('2026-10-13T09:00:00+08:00'));
    const fresh = await mountWith({
      checked_at: '2026-10-13 02:00:05', mode: 'live', threshold: 0, total_checked: 5, mismatch_count: 0, cause_counts: {}, mismatches: [],
    });
    expect(fresh.find('[data-testid="nr-stale"]').exists()).toBe(false);
    const yesterday = await mountWith({
      checked_at: '2026-10-12 23:59:59', mode: 'live', threshold: 0, total_checked: 5, mismatch_count: 0, cause_counts: {}, mismatches: [],
    });
    expect(yesterday.find('[data-testid="nr-stale"]').exists()).toBe(true);
  });

  it('keeps the stale warning when the newest report is a dry-run preview (#3945)', async () => {
    vi.setSystemTime(new Date('2026-10-13T10:05:00+08:00'));
    const wrapper = await mountWith({
      checked_at: '2026-10-13 10:00:00', mode: 'dry-run', threshold: 0, total_checked: 5, mismatch_count: 0, cause_counts: {}, mismatches: [],
    });
    expect(wrapper.find('[data-testid="nr-stale"]').exists()).toBe(true);
    expect(wrapper.get('[data-testid="nr-verdict"]').text()).not.toContain('昨晚');
  });

  it('says the detail list is truncated when mismatch_count exceeds the stored rows (#3945)', async () => {
    const wrapper = await mountWith({
      checked_at: '2026-10-13 02:00:05', mode: 'live', threshold: 0, total_checked: 900, mismatch_count: 250,
      cause_counts: { contract_cap: 250 },
      mismatches: [row(31, 'contract_cap', 1), row(32, 'contract_cap', 2)],
    });
    expect(wrapper.get('[data-testid="nr-truncated"]').text()).toContain('列出前 2 筆');
    expect(wrapper.get('[data-testid="nr-truncated"]').text()).toContain('共 250 筆');
    expect(wrapper.get('.nr-info').text()).toContain('只列出前 2 筆');
  });

  it('loads every mismatch row past the stored 200 when asked (#4044)', async () => {
    const all = Array.from({ length: 250 }, (_, i) => row(100 + i, 'contract_cap', 1));
    api.getReconcileRows.mockImplementation(async (_token, page) => ({
      rows: all.slice((page - 1) * 200, page * 200),
      meta: { checked_at: '2026-10-13 02:00:05', page, last_page: 2, total: 250, complete: true },
    }));
    const wrapper = await mountWith({
      checked_at: '2026-10-13 02:00:05', mode: 'live', threshold: 0, total_checked: 900, mismatch_count: 250,
      cause_counts: { contract_cap: 250 }, mismatches: all.slice(0, 200),
    });
    expect(wrapper.get('.nr-info').text()).toContain('200 / 200');

    await wrapper.get('[data-testid="nr-load-all"]').trigger('click');
    await flushPromises();

    expect(api.getReconcileRows.mock.calls.map((call) => call[1])).toEqual([1, 2]);
    expect(wrapper.find('[data-testid="nr-truncated"]').exists()).toBe(false);
    expect(wrapper.get('.nr-info').text()).toContain('250 / 250');
    expect(wrapper.get('.nr-info').text()).not.toContain('只列出');
    expect(wrapper.text()).toContain('學生349');
  });

  it('says the rest cannot be loaded when the report has no full row list', async () => {
    api.getReconcileRows.mockResolvedValue({
      rows: [row(31, 'contract_cap', 1), row(32, 'contract_cap', 2)],
      meta: { checked_at: '2026-10-13 02:00:05', page: 1, last_page: 1, total: 2, complete: false },
    });
    const wrapper = await mountWith({
      checked_at: '2026-10-13 02:00:05', mode: 'live', threshold: 0, total_checked: 900, mismatch_count: 250,
      cause_counts: { contract_cap: 250 }, mismatches: [row(31, 'contract_cap', 1), row(32, 'contract_cap', 2)],
    });
    await wrapper.get('[data-testid="nr-load-all"]').trigger('click');
    await flushPromises();

    expect(wrapper.get('[data-testid="nr-truncated"]').text()).toContain('沒有保存其餘明細');
    expect(wrapper.find('[data-testid="nr-load-all"]').exists()).toBe(false);
  });

  it('shows no truncation notice when every row is stored', async () => {
    const wrapper = await mountWith({
      checked_at: '2026-10-13 02:00:05', mode: 'live', threshold: 0, total_checked: 5, mismatch_count: 1,
      cause_counts: { contract_cap: 1 }, mismatches: [row(41, 'contract_cap', 1)],
    });
    expect(wrapper.find('[data-testid="nr-truncated"]').exists()).toBe(false);
  });

  it('uses a source-neutral contract-cap heading and names every evidence source in the next steps (#3945)', async () => {
    const wrapper = await mountWith({
      checked_at: '2026-10-13 02:00:05', mode: 'live', threshold: 0, total_checked: 5, mismatch_count: 1,
      cause_counts: { contract_cap: 1 }, mismatches: [row(51, 'contract_cap', 1)],
    });
    const heading = wrapper.get('.nr-guide-item h3').text();
    expect(heading).toContain('使用紀錄超出合約堂數');
    expect(heading).not.toContain('出勤');
    for (const category of ['attendance_ahead', 'counter_overstated', 'source_conflict']) {
      for (const source of ['點名', '課堂', '評量', '扣堂']) {
        expect(CATEGORY_GUIDE[category].next, `${category} ${source}`).toContain(source);
      }
    }
    expect(CATEGORY_GUIDE.source_conflict.next).toContain('退回');
    expect(CATEGORY_GUIDE.counter_overstated.next).not.toContain('課程查找');
  });

  it('names every source the backend counts as used, and limits the purchase cap to count-mode courses', () => {
    const service = readFileSync(resolve(__dirname, '../../../../backend/app/Services/SessionDeductionService.php'), 'utf8');
    // observed_used = max(deducted sign-ins, completed ClassSession statuses, orphan approved LearningRecords).
    expect(service).toContain("->whereIn('Status', ['completed', 'attended', 'late'])");
    expect(service).toContain("->where('Status', 'approved')");
    expect(service).toContain("$isSessionMode = (string) ($course->ScheduleMode ?? 'count') === 'count';");
    for (const category of ['ledger_ahead', 'contract_cap']) {
      const text = CATEGORY_GUIDE[category].meaning + CATEGORY_GUIDE[category].next;
      for (const source of ['點名', '課堂', '評量', '扣堂']) expect(text, `${category} ${source}`).toContain(source);
    }
    expect(CATEGORY_GUIDE.ledger_ahead.next).toContain('退回');
    expect(CATEGORY_GUIDE.contract_cap.meaning).toContain('只檢查堂數制');
    const panel = readFileSync(resolve(__dirname, '../../pages/NightlyReconcilePanel.vue'), 'utf8');
    expect(panel).toContain('月結（依上課日收費）的課沒有這個上限');
  });

  it('has a meaning and a next step for every category the nightly command can emit', () => {
    const command = readFileSync(resolve(__dirname, '../../../../backend/app/Console/Commands/NightlyReconcile.php'), 'utf8');
    const classify = command.slice(command.indexOf('function classifyMismatch'), command.indexOf('function causeLabel'));
    const emitted = [...classify.matchAll(/return '([a-z_]+)';/g)].map((m) => m[1]);
    expect(emitted.length).toBeGreaterThan(0);
    for (const category of [...emitted, 'unknown']) {
      expect(CATEGORY_GUIDE[category]?.meaning, category).toBeTruthy();
      expect(CATEGORY_GUIDE[category]?.next, category).toBeTruthy();
    }
  });
});
