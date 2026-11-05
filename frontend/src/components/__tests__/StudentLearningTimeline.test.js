import { describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

const ITEMS = [
  { type: 'attendance', id: 3, date: '2026-10-15', subject: '數學', attendance: '缺席' },
  { type: 'assessment', id: 2, date: '2026-10-08', subject: '英文', title: '單元檢測', score: 80, max_score: 100, approval_status: 'submitted' },
  { type: 'evaluation', id: 1, date: '2026-10-01', subject: '數學', attendance: '到班', approval_status: 'approved', homework_status: '完成', next_week_scope: '第三章' },
];
vi.mock('../../lib/authedFetch', () => ({ authedFetch: vi.fn(async () => ({ ok: true, json: async () => ({ items: ITEMS }) })) }));
import StudentLearningTimeline from '../students/StudentLearningTimeline.vue';

describe('StudentLearningTimeline', () => {
  it('lists rows newest first and filters by subject', async () => {
    const w = mount(StudentLearningTimeline, { props: { show: true, studentId: 1, studentName: '虛構學生' } });
    await flushPromises();
    expect(w.findAll('[data-testid="timeline-row"]').map((r) => r.text().slice(0, 10))).toEqual(['2026-10-15', '2026-10-08', '2026-10-01']);
    expect(w.text()).toContain('下週範圍：第三章');
    await w.get('[data-testid="timeline-subject"]').setValue('數學');
    expect(w.findAll('[data-testid="timeline-row"]')).toHaveLength(2);
    await w.get('[data-testid="timeline-subject"]').setValue('英文');
    expect(w.text()).toContain('80/100');
  });
});
