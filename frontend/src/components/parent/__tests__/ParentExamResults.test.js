import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('../../../api', () => ({ getParentExamResults: vi.fn() }));
import { getParentExamResults } from '../../../api';
import ParentExamResults from '../ParentExamResults.vue';

const ROUNDS = [{
  id: 7, title: '第一次段考', exam_date: '2026-10-15', subjects: [
    { name: '國文', max_score: 100, score: 85, is_absent: false, parent_comment: '閱讀進步。\n作文再加油。' },
    { name: '英文', max_score: 100, score: null, is_absent: true, parent_comment: null },
  ],
}];

const wrappers = [];
const mountIt = async () => {
  const w = mount(ParentExamResults, { props: { token: 'tok-a' }, attachTo: document.body });
  wrappers.push(w);
  await flushPromises();
  return w;
};

beforeEach(() => { getParentExamResults.mockReset(); getParentExamResults.mockResolvedValue({ data: ROUNDS }); });
afterEach(() => { wrappers.splice(0).forEach((w) => w.unmount()); document.body.innerHTML = ''; });

describe('ParentExamResults', () => {
  it('shows each subject with 85 / 100 or 缺考, and the teacher\'s words as plain text with line breaks kept', async () => {
    const w = await mountIt();
    expect(getParentExamResults).toHaveBeenCalledWith('tok-a');
    const round = w.find('[data-testid="exam-round"]');
    expect(round.find('h4').text()).toContain('第一次段考');
    expect(round.find('h4').text()).toContain('10/15');
    const subjects = w.findAll('[data-testid="exam-subject"]');
    expect(subjects.map((s) => s.find('p').text().replace(/\s+/g, ' '))).toEqual(['國文 85 / 100', '英文 缺考']);
    const comment = w.find('[data-testid="exam-comment"]');
    expect(comment.element.textContent).toBe('老師的話：閱讀進步。\n作文再加油。');
    expect(comment.classes()).toContain('tw:whitespace-pre-line');
    expect(subjects[1].find('[data-testid="exam-comment"]').exists()).toBe(false);
  });

  it('renders a comment as text, never as HTML', async () => {
    getParentExamResults.mockResolvedValue({ data: [{ ...ROUNDS[0], subjects: [{ ...ROUNDS[0].subjects[0], parent_comment: '<b>粗體</b>' }] }] });
    const w = await mountIt();
    expect(w.find('[data-testid="exam-comment"] b').exists()).toBe(false);
    expect(w.find('[data-testid="exam-comment"]').text()).toContain('<b>粗體</b>');
  });

  it('shows nothing when there are no rounds, or when the request fails', async () => {
    getParentExamResults.mockResolvedValue({ data: [] });
    expect((await mountIt()).find('[data-testid="parent-exam-results"]').exists()).toBe(false);
    getParentExamResults.mockRejectedValue(Object.assign(new Error('x'), { status: 500 }));
    const w = await mountIt();
    expect(w.find('[data-testid="parent-exam-results"]').exists()).toBe(false);
    expect(w.text()).toBe('');
  });
});
