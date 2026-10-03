import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { teacherWorkQueuePhase } from '../../lib/teacherWorkQueuePresentation.js';
import TeacherHomePage from '../../pages/TeacherHomePage.vue';
import { fetchClassSessions, fetchClassSessionsProjection } from '../../lib/classSessionsApi';

vi.mock('../../supabase', () => ({
  supabase: { auth: { getSession: async () => ({ data: { session: { access_token: 'test-token' } } }) } },
}));
vi.mock('../../lib/classSessionsApi', () => ({
  fetchClassSessions: vi.fn(),
  fetchClassSessionsProjection: vi.fn(),
}));
vi.mock('../../lib/adoptionTelemetry', () => ({ trackAdoptionEvent: vi.fn() }));

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/TeacherHomePage.vue'), 'utf8');

describe('Teacher Home progressive work queue', () => {
  it('keeps critical sources and critical errors fail-closed', () => {
    expect(teacherWorkQueuePhase({ criticalLoading: true, supplementalLoading: true, taskCount: 1 })).toBe('loading');
    expect(teacherWorkQueuePhase({ criticalLoading: true, taskCount: 1, hasError: true })).toBe('error');
    expect(teacherWorkQueuePhase({ hasError: true, taskCount: 0 })).toBe('error');
  });

  it('reveals known actions while supplemental sources are pending without a false all-clear', () => {
    expect(teacherWorkQueuePhase({ supplementalLoading: true, taskCount: 2 })).toBe('partial');
    expect(teacherWorkQueuePhase({ supplementalLoading: true, taskCount: 0 })).toBe('loading');
    expect(teacherWorkQueuePhase({ taskCount: 2 })).toBe('ready');
    expect(teacherWorkQueuePhase({ taskCount: 0 })).toBe('empty');
  });

  it('wires the progressive phase into the actual queue and labels provisional order/count', () => {
    expect(source).toContain('teacherWorkQueuePhase({');
    expect(source).toContain('criticalLoading: loadingAttendance.value || (loadingWeek.value && !weekLoadedOnce.value)');
    expect(source).toContain('supplementalLoading: loadingOverdue.value || awaitingReplyLoading.value');
    expect(source).toContain("teacherTasksPhase.value === 'partial'");
    expect(source).toContain('至少 ${teacherTaskCount} 項');
    expect(source).toContain('其他待辦仍在整理，項目與順序可能變動。');
    expect(source).toContain("teacherTasksProgressive ? '已載入，可先做' : '現在先做'");
  });

  it('renders a real attendance action while overdue reminders are still pending', async () => {
    let finishOverdue;
    const overdue = new Promise((resolve) => { finishOverdue = resolve; });
    const today = new Date();
    const todayYmd = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
    fetchClassSessions.mockImplementation(({ start, end }) => start === end
      ? Promise.resolve({ items: [{ id: 17, kind: 'materialized', status: 'scheduled', date: todayYmd, startTime: '10:00', endTime: '12:00', studentName: '測試學生', subjectName: '數學', branchId: 9 }] })
      : overdue);
    fetchClassSessionsProjection.mockResolvedValue({ items: [] });
    const originalFetch = globalThis.fetch;
    globalThis.fetch = vi.fn(async (url) => ({
      ok: true,
      json: async () => url.includes('awaiting-reply-count') ? { awaiting_reply_count: 0 } : { status: 'no_record' },
    }));

    const wrapper = mount(TeacherHomePage, {
      props: { branchId: 9, teacherBranchIds: [9], userRole: 'teacher' },
      global: { stubs: { ReportDiscrepancyModal: true, EngagementRankStrip: true } },
    });
    try {
      await flushPromises();
      const queue = wrapper.get('#teacher-work-queue');
      expect(queue.text()).toContain('至少 1 項');
      expect(queue.text()).toContain('其他待辦仍在整理，項目與順序可能變動。');
      expect(queue.text()).toContain('待點名');
      expect(queue.text()).not.toContain('今天沒有待完成工作');
      await queue.get('.th-next-action__cta').trigger('click');
      expect(wrapper.emitted('navigate')?.[0]).toEqual(['attendance']);

      finishOverdue({ items: [] });
      await flushPromises();
      expect(queue.text()).toContain('1 項');
      expect(queue.text()).not.toContain('其他待辦仍在整理，項目與順序可能變動。');
    } finally {
      wrapper.unmount();
      globalThis.fetch = originalFetch;
    }
  });
});
