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
    expect(wrapper.get('[data-testid="nr-verdict"]').text()).toContain('3 門課程的堂數對不上');
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
