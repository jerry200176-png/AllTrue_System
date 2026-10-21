import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('../../supabase', () => ({
  supabase: { auth: { getSession: async () => ({ data: { session: { access_token: 'synthetic-token' } } }) } },
}));
import ExamGradesPage from '../../pages/ExamGradesPage.vue';
import { canEditExamRound, examRoundActionLabel, filterExamStudents } from '../../lib/examScoreStudentFilter';

afterEach(() => vi.unstubAllGlobals());

const subjects = [{ id: 11, name: '國文', max_score: 100 }, { id: 12, name: '數學', max_score: 100 }];
const rounds = [
  { id: 1, title: '合成段考一', status: 'published', subjects },
  { id: 2, title: '合成段考二', status: 'closed', subjects },
  { id: 3, title: '合成段考三', status: 'draft', subjects },
];
const studentRows = [
  { student_id: 501, student_name: '合成甲', filled_subject_count: 0, required_subject_count: 2, scores: [] },
  { student_id: 502, student_name: '合成乙', filled_subject_count: 0, required_subject_count: 2, scores: [] },
  { student_id: 1503, student_name: '測試丙', filled_subject_count: 0, required_subject_count: 2, scores: [] },
];

function stubFetch() {
  const fetchMock = vi.fn(async (input, options = {}) => {
    const url = String(input);
    const body = url.includes('/students') ? { data: studentRows }
      : url.includes('/scores') ? { data: [] }
        : { data: rounds };
    return { ok: true, json: async () => body, method: options.method };
  });
  vi.stubGlobal('fetch', fetchMock);
  return fetchMock;
}

const roundButtons = (wrapper) => wrapper.findAll('.exam-round-card__actions button')
  .filter((b) => /登分|查看/.test(b.text()));

describe('In-App #420: 段考成績 student search and director entry label', () => {
  it('filters by name or exact student number', () => {
    expect(filterExamStudents(studentRows, '').length).toBe(3);
    expect(filterExamStudents(studentRows, ' 合成 ').map((r) => r.student_id)).toEqual([501, 502]);
    expect(filterExamStudents(studentRows, '乙').map((r) => r.student_id)).toEqual([502]);
    expect(filterExamStudents(studentRows, '502').map((r) => r.student_id)).toEqual([502]);
    expect(filterExamStudents(studentRows, '50')).toEqual([]);
    expect(filterExamStudents(null, 'x')).toEqual([]);
  });

  it('labels rounds by who can actually enter scores', () => {
    expect(examRoundActionLabel(rounds[0], false)).toBe('登分');
    expect(examRoundActionLabel(rounds[1], true)).toBe('查看／登分');
    expect(examRoundActionLabel(rounds[1], false)).toBe('查看');
    expect(examRoundActionLabel(rounds[2], true)).toBe('查看');
    expect(canEditExamRound(rounds[2], true)).toBe(false);
    expect(canEditExamRound(rounds[1], false)).toBe(false);
  });

  it('narrows the score table to one student and still saves that row', async () => {
    const fetchMock = stubFetch();
    const wrapper = mount(ExamGradesPage, { props: { branchId: 1, userRole: 'director' }, attachTo: document.body });
    try {
      await flushPromises();
      expect(roundButtons(wrapper).map((b) => b.text())).toEqual(['登分', '查看／登分', '查看']);

      await roundButtons(wrapper)[0].trigger('click');
      await flushPromises();
      const dialog = () => document.body;
      expect(dialog().querySelectorAll('.exam-score-table tbody tr')).toHaveLength(3);

      const search = dialog().querySelector('#exam-score-search-input');
      search.value = '乙';
      search.dispatchEvent(new Event('input'));
      await flushPromises();
      const rows = dialog().querySelectorAll('.exam-score-table tbody tr');
      expect(rows).toHaveLength(1);
      expect(rows[0].textContent).toContain('合成乙');

      const input = rows[0].querySelector('.exam-score-input');
      input.value = '88';
      input.dispatchEvent(new Event('change'));
      rows[0].querySelector('button').click();
      await flushPromises();

      const post = fetchMock.mock.calls.find(([, o]) => o?.method === 'POST');
      expect(post[0]).toContain('/exam-rounds/1/scores');
      expect(JSON.parse(post[1].body).scores).toEqual([
        { student_id: 502, exam_round_subject_id: 11, score: 88, is_absent: false },
      ]);
      expect(search.value).toBe('乙');

      search.value = '不存在';
      search.dispatchEvent(new Event('input'));
      await flushPromises();
      expect(dialog().querySelector('.exam-score-no-match')?.textContent).toContain('不存在');
    } finally {
      wrapper.unmount();
    }
  });

  it('teachers keep the plain labels', async () => {
    stubFetch();
    const wrapper = mount(ExamGradesPage, { props: { branchId: 1, userRole: 'teacher' } });
    try {
      await flushPromises();
      expect(roundButtons(wrapper).map((b) => b.text())).toEqual(['登分', '查看', '查看']);
    } finally {
      wrapper.unmount();
    }
  });
});
