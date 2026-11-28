import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('../../supabase', () => ({
  supabase: { auth: { getSession: async () => ({ data: { session: { access_token: 'synthetic-token' } } }) } },
}));
import ExamGradesPage from '../../pages/ExamGradesPage.vue';
import ExamScoreCell from '../exams/ExamScoreCell.vue';
import { canEditExamRound } from '../../lib/examScoreStudentFilter';
import { examErrorMessage, historyActionLabel } from '../../lib/examScoresApi';

afterEach(() => vi.unstubAllGlobals());

describe('ExamScoreCell states', () => {
  it('editable: number input, 缺考 checkbox, 清除 button emit events', async () => {
    const w = mount(ExamScoreCell, { props: { score: 70, max: 100, editable: true } });
    await w.find('input[type=number]').setValue('80');
    expect(w.emitted('change')[0][0]).toEqual({ score: 80, is_absent: false });
    await w.find('input[type=checkbox]').setValue(true);
    expect(w.emitted('change')[1][0]).toEqual({ score: null, is_absent: true });
    await w.find('button.score-cell__btn').trigger('click');
    expect(w.emitted('clear')).toHaveLength(1);
    expect(w.find('.score-cell__edited').exists()).toBe(false);
  });

  it('shows 改過 badge that opens history, and disables 清除 when blank', async () => {
    const w = mount(ExamScoreCell, { props: { score: null, edited: true } });
    expect(w.find('button.score-cell__btn').attributes('disabled')).toBeDefined();
    await w.find('.score-cell__edited').trigger('click');
    expect(w.emitted('history')).toHaveLength(1);
  });

  it('read-only shows the score and 已截止，請找主任更正 with no inputs', () => {
    const w = mount(ExamScoreCell, { props: { score: 66, editable: false } });
    expect(w.text()).toContain('66');
    expect(w.text()).toContain('已截止，請找主任更正');
    expect(w.find('input').exists()).toBe(false);
  });
});

describe('canEditExamRound with deadline', () => {
  const now = new Date(2026, 10, 10);
  it('teacher is blocked the day after the deadline, allowed on it', () => {
    expect(canEditExamRound({ status: 'published', entry_deadline: '2026-11-10' }, false, now)).toBe(true);
    expect(canEditExamRound({ status: 'published', entry_deadline: '2026-11-09' }, false, now)).toBe(false);
    expect(canEditExamRound({ status: 'published' }, false, now)).toBe(true);
  });
  it('director ignores the deadline but not draft', () => {
    expect(canEditExamRound({ status: 'published', entry_deadline: '2026-11-01' }, true, now)).toBe(true);
    expect(canEditExamRound({ status: 'closed', entry_deadline: '2026-11-01' }, true, now)).toBe(true);
    expect(canEditExamRound({ status: 'draft' }, true, now)).toBe(false);
  });
});

describe('historyActionLabel', () => {
  it('a comment-only change reads 老師的話已更新', () => {
    expect(historyActionLabel('score_comment_updated')).toBe('老師的話已更新');
  });
});

describe('examErrorMessage', () => {
  it('maps 409 / 422 / 403 to Chinese', () => {
    expect(examErrorMessage({ status: 409, raw: 'Entry deadline has passed' })).toBe('已截止，請找主任更正');
    expect(examErrorMessage({ status: 409, raw: 'Round is not open for entry' })).toContain('不開放登分');
    expect(examErrorMessage({ status: 422, raw: 'Score must be between 0 and 100' })).toBe('分數要在 0 到 100 之間');
    expect(examErrorMessage({ status: 422, raw: 'Score is required unless marked absent' })).toBe('請填分數，或勾選缺考');
    expect(examErrorMessage({ status: 422, raw: '更正已截止的段考成績需填寫原因' })).toBe('更正已截止的段考成績需填寫原因');
    expect(examErrorMessage({ status: 403, raw: 'Forbidden' })).toContain('權限');
    expect(examErrorMessage({ status: 409, raw: 'x' })).toContain('不能登分');
  });
});

describe('page: enter wrong score, clear, re-enter', () => {
  it('sends score, then clear, then score again; closed round asks a reason', async () => {
    const subjects = [{ id: 11, name: '國文', max_score: 100 }];
    const rounds = [{ id: 2, title: '段考二', status: 'closed', subjects }];
    const rows = [{ student_id: 501, student_name: '合成甲', filled_subject_count: 1, required_subject_count: 1, scores: [{ exam_round_subject_id: 11, score: 5, is_absent: false, edited: false }] }];
    const fetchMock = vi.fn(async (input) => ({
      ok: true,
      json: async () => (String(input).includes('/students') ? { data: rows } : String(input).includes('/scores') ? { data: [] } : { data: rounds }),
    }));
    vi.stubGlobal('fetch', fetchMock);
    const wrapper = mount(ExamGradesPage, { props: { branchId: 1, userRole: 'director' }, attachTo: document.body });
    try {
      await flushPromises();
      await wrapper.findAll('.exam-round-card__actions button').find((b) => /登分/.test(b.text())).trigger('click');
      await flushPromises();
      const body = document.body;
      const posts = () => fetchMock.mock.calls.filter(([, o]) => o?.method === 'POST').map(([, o]) => JSON.parse(o.body));
      const saveRow = async () => {
        [...body.querySelectorAll('.exam-score-table tbody button')].find((b) => b.textContent.trim() === '儲存').click();
        await flushPromises();
      };
      const setReason = async (text) => {
        const input = [...body.querySelectorAll('input')].find((i) => i.placeholder === '例如：登錄時打錯');
        input.value = text;
        input.dispatchEvent(new Event('input'));
        await flushPromises();
        // The reason is an inline panel inside the score dialog, not a second dialog on top of it.
        expect(body.querySelectorAll('[role=dialog]')).toHaveLength(1);
        [...body.querySelectorAll('button')].find((b) => b.textContent.trim() === '確定更正').click();
        await flushPromises();
      };
      const input = () => body.querySelector('.exam-score-table .exam-score-input');
      const clearBtn = () => body.querySelector('.exam-score-table .score-cell__btn');

      input().value = '50'; input().dispatchEvent(new Event('change'));
      await saveRow(); await setReason('打錯');
      expect(posts()[0]).toEqual({ scores: [{ student_id: 501, exam_round_subject_id: 11, score: 50, is_absent: false }], reason: '打錯' });

      clearBtn().click(); await flushPromises();
      await saveRow(); await setReason('清掉');
      expect(posts()[1].scores[0]).toEqual({ student_id: 501, exam_round_subject_id: 11, clear: true });

      input().value = '90'; input().dispatchEvent(new Event('change'));
      await saveRow(); await setReason('重填');
      expect(posts()[2].scores[0].score).toBe(90);
    } finally {
      wrapper.unmount();
    }
  });

  it('a typed-then-erased cell in another subject is skipped, not sent as a blank score', async () => {
    const subjects = [{ id: 11, name: '國文', max_score: 100 }, { id: 12, name: '英文', max_score: 100 }];
    const rounds = [{ id: 3, title: '段考三', status: 'published', subjects }];
    const rows = [{ student_id: 502, student_name: '合成乙', filled_subject_count: 0, required_subject_count: 2, scores: [] }];
    const fetchMock = vi.fn(async (input) => ({
      ok: true,
      json: async () => (String(input).includes('/students') ? { data: rows } : String(input).includes('/scores') ? { data: [] } : { data: rounds }),
    }));
    vi.stubGlobal('fetch', fetchMock);
    const wrapper = mount(ExamGradesPage, { props: { branchId: 1, userRole: 'director' }, attachTo: document.body });
    try {
      await flushPromises();
      await wrapper.findAll('.exam-round-card__actions button').find((b) => /登分/.test(b.text())).trigger('click');
      await flushPromises();
      const [a, b] = document.body.querySelectorAll('.exam-score-table .exam-score-input');
      a.value = '80'; a.dispatchEvent(new Event('change'));
      b.value = '7'; b.dispatchEvent(new Event('change'));
      b.value = ''; b.dispatchEvent(new Event('change'));
      [...document.body.querySelectorAll('.exam-score-table tbody button')].find((x) => x.textContent.trim() === '儲存').click();
      await flushPromises();
      const posts = fetchMock.mock.calls.filter(([, o]) => o?.method === 'POST').map(([, o]) => JSON.parse(o.body));
      expect(posts[0].scores).toEqual([{ student_id: 502, exam_round_subject_id: 11, score: 80, is_absent: false }]);
    } finally {
      wrapper.unmount();
    }
  });

  it('老師的話: the toggle opens one box per subject; a comment rides with the score on the row 儲存', async () => {
    const subjects = [{ id: 11, name: '國文', max_score: 100 }, { id: 12, name: '英文', max_score: 100 }];
    const rounds = [{ id: 4, title: '段考四', status: 'published', subjects }];
    const rows = [{ student_id: 503, student_name: '合成丙', filled_subject_count: 1, required_subject_count: 2, scores: [{ exam_round_subject_id: 11, score: 70, is_absent: false, edited: false, parent_comment: null }] }];
    const fetchMock = vi.fn(async (input) => ({
      ok: true,
      json: async () => (String(input).includes('/students') ? { data: rows } : String(input).includes('/scores') ? { data: [] } : { data: rounds }),
    }));
    vi.stubGlobal('fetch', fetchMock);
    const wrapper = mount(ExamGradesPage, { props: { branchId: 1, userRole: 'teacher' }, attachTo: document.body });
    try {
      await flushPromises();
      await wrapper.findAll('.exam-round-card__actions button').find((b) => /登分/.test(b.text())).trigger('click');
      await flushPromises();
      const body = document.body;
      const toggle = body.querySelector('[data-testid=exam-comment-toggle]');
      expect(toggle.getAttribute('aria-expanded')).toBe('false');
      expect(body.querySelector('[data-testid=exam-comment-row]')).toBeNull();
      toggle.click();
      await flushPromises();
      expect(toggle.getAttribute('aria-expanded')).toBe('true');
      const boxes = body.querySelectorAll('.exam-comment-input');
      expect(boxes).toHaveLength(2);
      expect([...body.querySelectorAll('[data-testid=exam-comment-row] label')].map((l) => l.textContent.trim())).toEqual(['國文', '英文']);
      expect(boxes[0].getAttribute('maxlength')).toBe('500');

      boxes[0].value = ' 進步很多 ';
      boxes[0].dispatchEvent(new Event('input'));
      await flushPromises();
      expect(body.querySelector('[data-testid=exam-comment-row]').textContent).toContain('6/500');
      expect(body.querySelector('.exam-score-table tbody').textContent).toContain('已寫 1 科');
      const save = () => [...body.querySelectorAll('.exam-score-table tbody button')].find((b) => b.textContent.trim() === '儲存').click();
      save();
      await flushPromises();
      const posts = () => fetchMock.mock.calls.filter(([, o]) => o?.method === 'POST').map(([, o]) => JSON.parse(o.body));
      // An untouched score is not resent with the comment (the server keeps the stored one).
      expect(posts()[0]).toEqual({ scores: [{ student_id: 503, exam_round_subject_id: 11, parent_comment: '進步很多' }] });

      // A comment on a subject with no score yet is not sent; the teacher is told to fill the score first.
      const [, english] = body.querySelectorAll('.exam-comment-input');
      english.value = '單字要背';
      english.dispatchEvent(new Event('input'));
      await flushPromises();
      save();
      await flushPromises();
      expect(posts()).toHaveLength(1);
      expect(body.querySelector('.exam-error').textContent).toContain('「英文」要先填分數或勾缺考');
    } finally {
      wrapper.unmount();
    }
  });
  it('老師的話: saving one row keeps the other rows\' drafts; a comment typed then erased on an empty cell is no change', async () => {
    const subjects = [{ id: 11, name: '國文', max_score: 100 }, { id: 12, name: '英文', max_score: 100 }];
    const rounds = [{ id: 5, title: '段考五', status: 'published', subjects }];
    const cell = (score) => [{ exam_round_subject_id: 11, score, is_absent: false, edited: false, parent_comment: null }];
    const rows = [
      { student_id: 503, student_name: '合成丙', filled_subject_count: 1, required_subject_count: 2, scores: cell(70) },
      { student_id: 504, student_name: '合成丁', filled_subject_count: 1, required_subject_count: 2, scores: cell(60) },
    ];
    const fetchMock = vi.fn(async (input) => ({
      ok: true,
      json: async () => (String(input).includes('/students') ? { data: rows } : String(input).includes('/scores') ? { data: [] } : { data: rounds }),
    }));
    vi.stubGlobal('fetch', fetchMock);
    const wrapper = mount(ExamGradesPage, { props: { branchId: 1, userRole: 'teacher' }, attachTo: document.body });
    try {
      await flushPromises();
      await wrapper.findAll('.exam-round-card__actions button').find((b) => /登分/.test(b.text())).trigger('click');
      await flushPromises();
      const body = document.body;
      body.querySelectorAll('[data-testid=exam-comment-toggle]').forEach((t) => t.click());
      await flushPromises();
      const type = (el, v) => { el.value = v; el.dispatchEvent(new Event('input')); };
      let boxes = body.querySelectorAll('.exam-comment-input');
      expect(boxes).toHaveLength(4);
      type(boxes[2], '加油');
      type(boxes[3], '先寫');
      await flushPromises();
      type(body.querySelectorAll('.exam-comment-input')[3], '');
      const score = body.querySelectorAll('.exam-score-table .exam-score-input')[0];
      score.value = '75'; score.dispatchEvent(new Event('change'));
      await flushPromises();
      const saves = () => [...body.querySelectorAll('.exam-score-table tbody button')].filter((b) => b.textContent.trim() === '儲存');
      const posts = () => fetchMock.mock.calls.filter(([, o]) => o?.method === 'POST').map(([, o]) => JSON.parse(o.body));
      saves()[0].click();
      await flushPromises();
      expect(posts()[0]).toEqual({ scores: [{ student_id: 503, exam_round_subject_id: 11, score: 75, is_absent: false }] });
      boxes = body.querySelectorAll('.exam-comment-input');
      expect(boxes[2].value).toBe('加油');
      saves()[1].click();
      await flushPromises();
      expect(body.querySelector('.exam-error')).toBeNull();
      expect(posts()[1]).toEqual({ scores: [{ student_id: 504, exam_round_subject_id: 11, parent_comment: '加油' }] });
    } finally {
      wrapper.unmount();
    }
  });
});
