import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

vi.mock('../../api', () => ({ getReconcileLatest: vi.fn() }));

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
  });

  it('warns when the latest report is more than a day old', async () => {
    const wrapper = await mountWith({
      checked_at: '2026-10-10 02:00:05', threshold: 0, total_checked: 5, mismatch_count: 0, cause_counts: {}, mismatches: [],
    });
    expect(wrapper.get('[data-testid="nr-stale"]').text()).toContain('通知工程團隊');
    // A stale report must not claim to be last night's.
    expect(wrapper.get('[data-testid="nr-verdict"]').text()).toContain('最近一次核對無異常');
    expect(wrapper.get('[data-testid="nr-verdict"]').text()).not.toContain('昨晚');
  });

  it('turns stale while the tab stays open, without a reload', async () => {
    vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] });
    vi.setSystemTime(new Date('2026-10-13T13:59:00+08:00'));
    const wrapper = await mountWith({
      checked_at: '2026-10-12 02:00:00', threshold: 0, total_checked: 5, mismatch_count: 0, cause_counts: {}, mismatches: [],
    });
    expect(wrapper.find('[data-testid="nr-stale"]').exists()).toBe(false);
    vi.advanceTimersByTime(2 * 60 * 1000);
    await flushPromises();
    expect(wrapper.find('[data-testid="nr-stale"]').exists()).toBe(true);
    expect(wrapper.get('[data-testid="nr-verdict"]').text()).not.toContain('昨晚');
    wrapper.unmount();
  });

  it('reads the offset-less server timestamp as Taipei time, not viewer-local time', async () => {
    // 2026-10-12 02:00 +08:00 is 31h before 2026-10-13 09:00 +08:00: not stale anywhere.
    vi.setSystemTime(new Date('2026-10-13T09:00:00+08:00'));
    const fresh = await mountWith({
      checked_at: '2026-10-12 02:00:00', threshold: 0, total_checked: 5, mismatch_count: 0, cause_counts: {}, mismatches: [],
    });
    expect(fresh.find('[data-testid="nr-stale"]').exists()).toBe(false);
    vi.setSystemTime(new Date('2026-10-13T15:00:01+08:00'));
    const stale = await mountWith({
      checked_at: '2026-10-12 02:00:00', threshold: 0, total_checked: 5, mismatch_count: 0, cause_counts: {}, mismatches: [],
    });
    expect(stale.find('[data-testid="nr-stale"]').exists()).toBe(true);
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
