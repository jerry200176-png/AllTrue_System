import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('../../supabase', () => ({
  supabase: { auth: { getSession: async () => ({ data: { session: { access_token: 'synthetic-token' } } }) } },
}));
import ExamGradesPage from '../../pages/ExamGradesPage.vue';

afterEach(() => { vi.unstubAllGlobals(); document.body.innerHTML = ''; });

const subjects = [{ id: 11, name: '國文', max_score: 100 }];
const rows = [{ student_id: 501, student_name: '合成甲', filled_subject_count: 1, required_subject_count: 1, is_complete: true, scores: [{ exam_round_subject_id: 11, score: 5, is_absent: false, edited: true }] }];

function stub({ closeReply } = {}) {
  const rounds = [{ id: 1, title: '合成段考', status: 'published', grade_ids: [3], subjects }];
  const fetchMock = vi.fn(async (input) => {
    const url = String(input);
    if (url.endsWith('/close')) return closeReply;
    if (url.includes('/students')) return { ok: true, json: async () => ({ data: rows }) };
    if (url.includes('/history')) return { ok: true, json: async () => ({ data: [{ id: 1, action: 'update', exam_round_subject_id: 11, before: { score: 3 }, after: { score: 5 }, created_at: '2026-11-01T10:00:00' }] }) };
    return { ok: true, json: async () => ({ data: url.includes('exam-rounds?') ? rounds : [] }) };
  });
  vi.stubGlobal('fetch', fetchMock);
  return fetchMock;
}

const button = (text) => [...document.body.querySelectorAll('button')].find((b) => b.textContent.trim() === text);
const mountPage = () => mount(ExamGradesPage, { props: { branchId: 1, userRole: 'director' }, attachTo: document.body });

describe('段考成績 dialogs on the overlay templates', () => {
  it('close: a failed request keeps the confirm open and shows why; success closes it', async () => {
    const fetchMock = stub({ closeReply: { ok: false, status: 500, json: async () => ({ message: '伺服器忙碌' }) } });
    const wrapper = mountPage();
    try {
      await flushPromises();
      await wrapper.findAll('.exam-round-card__actions button').find((b) => b.text() === '關閉').trigger('click');
      await flushPromises();
      expect(document.body.querySelector('[role=alertdialog]').textContent).toContain('關閉這個段考？');
      button('關閉段考').click();
      await flushPromises();
      expect(document.body.querySelector('[role=alertdialog]').textContent).toContain('伺服器忙碌');
      fetchMock.mockImplementation(async (input) => (String(input).endsWith('/close')
        ? { ok: true, json: async () => ({}) }
        : { ok: true, json: async () => ({ data: [] }) }));
      button('關閉段考').click();
      await flushPromises();
      expect(document.body.querySelector('[role=alertdialog]')).toBeNull();
    } finally {
      wrapper.unmount();
    }
  });

  it('create form: closing a changed form asks before discarding', async () => {
    stub();
    const wrapper = mountPage();
    try {
      await flushPromises();
      button('開新段考').click();
      await flushPromises();
      const title = document.body.querySelector('#exam-form-title');
      title.value = '新的一場';
      title.dispatchEvent(new Event('input'));
      await flushPromises();
      button('取消').click();
      await flushPromises();
      expect(document.body.textContent).toContain('放棄未儲存的變更？');
      button('放棄變更').click();
      await flushPromises();
      expect(document.body.querySelector('#exam-form-title')).toBeNull();
    } finally {
      wrapper.unmount();
    }
  });

  it('scores: history opens inline in the same dialog, and unsaved scores are not lost on close', async () => {
    stub();
    const wrapper = mountPage();
    try {
      await flushPromises();
      await wrapper.findAll('.exam-round-card__actions button').find((b) => /登分/.test(b.text())).trigger('click');
      await flushPromises();
      document.body.querySelector('.score-cell__edited').click();
      await flushPromises();
      expect(document.body.querySelectorAll('[role=dialog]')).toHaveLength(1);
      expect(document.body.querySelector('.exam-history-panel').textContent).toContain('修改紀錄：合成甲');
      expect(document.body.querySelector('.exam-history-panel').textContent).toContain('3 → 5');
      const input = document.body.querySelector('.exam-score-table .exam-score-input');
      input.value = '60';
      input.dispatchEvent(new Event('change'));
      await flushPromises();
      [...document.body.querySelectorAll('[role=dialog] button')].find((b) => b.textContent.trim() === '關閉' && !b.querySelector('svg')).click();
      await flushPromises();
      expect(document.body.textContent).toContain('放棄還沒儲存的分數？');
      expect(document.body.querySelector('.exam-score-table')).not.toBeNull();
      button('繼續登分').click();
      await flushPromises();
      expect(document.body.querySelector('.exam-score-table')).not.toBeNull();
    } finally {
      wrapper.unmount();
    }
  });

  it('scores: the table takes no input while a row is being saved', async () => {
    const fetchMock = stub();
    const wrapper = mountPage();
    try {
      await flushPromises();
      await wrapper.findAll('.exam-round-card__actions button').find((b) => /登分/.test(b.text())).trigger('click');
      await flushPromises();
      let release;
      const gate = new Promise((resolve) => { release = resolve; });
      const base = fetchMock.getMockImplementation();
      fetchMock.mockImplementation(async (input, options) => {
        if (options?.method === 'POST') await gate;
        return base(input, options);
      });
      const input = document.body.querySelector('.exam-score-table .exam-score-input');
      input.value = '60';
      input.dispatchEvent(new Event('change'));
      await flushPromises();
      [...document.body.querySelectorAll('.exam-score-table tbody button')].find((b) => b.textContent.trim() === '儲存').click();
      await flushPromises();
      expect(document.body.querySelector('.exam-score-table').hasAttribute('inert')).toBe(true);
      release();
      await flushPromises();
      expect(document.body.querySelector('.exam-score-table').hasAttribute('inert')).toBe(false);
    } finally {
      wrapper.unmount();
    }
  });
});
