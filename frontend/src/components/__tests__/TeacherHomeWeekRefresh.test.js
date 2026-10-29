// In-app #441: the teacher workbench week list was loaded once and never refreshed when the app returned to the
// foreground (the poll and visibility handlers skipped it), so a lesson a director assigned or restored meanwhile
// stayed missing on the teacher's screen until a manual reload.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import TeacherHomePage from '../../pages/TeacherHomePage.vue';
import { fetchClassSessions, fetchClassSessionsProjection } from '../../lib/classSessionsApi';

vi.mock('../../supabase', () => ({
  supabase: { auth: { getSession: async () => ({ data: { session: { access_token: 'synthetic-token' } } }) } },
}));
vi.mock('../../lib/classSessionsApi', () => ({
  fetchClassSessions: vi.fn(),
  fetchClassSessionsProjection: vi.fn(),
}));
vi.mock('../../lib/adoptionTelemetry', () => ({ trackAdoptionEvent: vi.fn() }));

const ok = (body) => ({ ok: true, json: async () => body });
const wrappers = [];
let now;

function render() {
  const w = mount(TeacherHomePage, {
    props: { branchId: 9, teacherBranchIds: [9], userRole: 'teacher' },
    global: { stubs: { ReportDiscrepancyModal: true, EngagementRankStrip: true, TeacherMonthlyAttendance: true } },
  });
  wrappers.push(w);
  return w;
}

const backToForeground = async () => {
  document.dispatchEvent(new Event('visibilitychange'));
  await flushPromises();
};

beforeEach(() => {
  now = 1_800_000_000_000;
  vi.spyOn(Date, 'now').mockImplementation(() => now);
  vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible');
  fetchClassSessions.mockResolvedValue({ items: [] });
  fetchClassSessionsProjection.mockResolvedValue({ items: [] });
  vi.stubGlobal('fetch', vi.fn((url) => Promise.resolve(ok(String(url).includes('awaiting-reply-count') ? { awaiting_reply_count: 0 } : []))));
});

afterEach(() => {
  for (const w of wrappers.splice(0)) w.unmount();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

const loads = () => fetchClassSessionsProjection.mock.calls.length;

describe('teacher workbench week list refreshes when the app returns to the foreground', () => {
  it('reloads the week after it has been stale for a minute', async () => {
    render();
    await flushPromises();
    const base = loads();
    now += 61_000;
    await backToForeground();
    expect(loads()).toBe(base + 1);
  });

  it('does not reload on every quick focus change', async () => {
    render();
    await flushPromises();
    const base = loads();
    now += 5_000;
    await backToForeground();
    await backToForeground();
    expect(loads()).toBe(base);
  });

  it('retries on the next foreground after a failed reload (a failure is not a fresh list)', async () => {
    render();
    await flushPromises();
    const base = loads();
    now += 61_000;
    fetchClassSessionsProjection.mockRejectedValueOnce(new Error('offline'));
    await backToForeground();
    expect(loads()).toBe(base + 1);
    await backToForeground();
    expect(loads()).toBe(base + 2);
  });
});
