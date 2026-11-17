import { mount, flushPromises } from '@vue/test-utils';
import { afterEach, beforeAll, expect, it, vi } from 'vitest';
import SessionHistorySheet from '../SessionHistorySheet.vue';

const mockFetch = vi.fn();
vi.mock('@/lib/authedFetch', () => ({ authedFetch: (...a) => mockFetch(...a) }));

beforeAll(() => {
  globalThis.ResizeObserver ||= class { observe() {} unobserve() {} disconnect() {} };
  Element.prototype.scrollIntoView ||= () => {};
});

const res = (body, ok = true, status = 200) => ({ ok, status, json: async () => body });
// One entry per change: who, what, and each changed field (the time depends on the runner's timezone, so it is not read).
const entries = () => [...document.body.querySelectorAll('[data-testid="session-history"] > li')].map((li) => [
  li.querySelector('div span:last-child').textContent.trim(), li.querySelector('p').textContent.trim(),
  ...[...li.querySelectorAll('ul li')].map((x) => x.textContent.trim()),
]);
let wrapper;
afterEach(() => { wrapper?.unmount(); mockFetch.mockReset(); });

it('loads the one session read-only and says what changed, who did it and when', async () => {
  mockFetch.mockResolvedValue(res({
    data: [
      { id: 2, action_type: 'update', created_at: '2026-11-17T01:30:00.000000Z', operator: { id: 7, Name: '王主任' },
        old_data: { SessionDate: '2026-11-20', StartTime: '10:00:00', Status: 'scheduled', session_charge: 900 },
        new_data: { SessionDate: '2026-11-21', StartTime: '10:00:00', Status: 'leave', session_charge: 900 } },
      { id: 3, action_type: 'update', created_at: '2026-11-16T01:30:00.000000Z', operator: null,
        old_data: { session_charge: 900 }, new_data: { session_charge: 1000 } },
      { id: 1, action_type: 'create', created_at: '2026-11-01T01:30:00.000000Z', operator: null,
        old_data: null, new_data: { SessionDate: '2026-11-20', StartTime: '10:00:00', EndTime: '12:00:00' } },
    ],
    meta: { total: 3 },
  }));
  wrapper = mount(SessionHistorySheet, { props: { open: true, sessionId: 42 }, attachTo: document.body });
  await flushPromises();

  expect(mockFetch).toHaveBeenCalledTimes(1);
  expect(mockFetch.mock.calls[0][0]).toBe('/api/v1/schedule-audit?session_id=42&per_page=50');
  expect(mockFetch.mock.calls[0][1]?.method).toBeUndefined(); // GET only
  expect(entries()).toEqual([
    ['王主任', '修改', '日期：2026-11-20 → 2026-11-21', '狀態：排課中 → 請假'], // the unchanged start time is not listed
    ['系統自動', '修改', '其他資料更新'],
    ['系統自動', '建立這堂課', '2026-11-20 10:00–12:00'],
  ]);
});

it('tells a non-director plainly and offers a retry on other errors', async () => {
  mockFetch.mockResolvedValue(res({ message: 'Forbidden' }, false, 403));
  wrapper = mount(SessionHistorySheet, { props: { open: true, sessionId: 42 }, attachTo: document.body });
  await flushPromises();

  expect(document.body.textContent).toContain('只有主任可以看異動紀錄。');
  expect([...document.body.querySelectorAll('button')].some((b) => b.textContent.includes('再試一次'))).toBe(true);
});

it('does not load while closed or without a session', async () => {
  wrapper = mount(SessionHistorySheet, { props: { open: false, sessionId: 42 } });
  await flushPromises();
  wrapper.unmount();
  wrapper = mount(SessionHistorySheet, { props: { open: true, sessionId: null }, attachTo: document.body });
  await flushPromises();

  expect(mockFetch).not.toHaveBeenCalled();
});
