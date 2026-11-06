import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('../../supabase', () => ({
  supabase: { auth: { getSession: async () => ({ data: { session: { access_token: 'synthetic-token' } } }) } },
}));
import ExamGradesPage from '../../pages/ExamGradesPage.vue';

afterEach(() => { vi.unstubAllGlobals(); document.body.innerHTML = ''; });

const subjects = [{ id: 11, name: '國文', max_score: 100 }];
const round = { id: 7, title: '合成段考', status: 'published', grade_ids: [3], subjects };

function stubFetch(deleteReply) {
  const fetchMock = vi.fn(async (input, options = {}) => {
    const url = String(input);
    if (options.method === 'DELETE') return deleteReply;
    if (options.method === 'PATCH') return { ok: true, json: async () => ({ data: round }) };
    return { ok: true, json: async () => ({ data: url.includes('exam-rounds?') ? [round] : [] }) };
  });
  vi.stubGlobal('fetch', fetchMock);
  return fetchMock;
}

async function openMenu(wrapper, action) {
  wrapper.find('.am__trigger').trigger('click');
  await flushPromises();
  document.body.querySelector(`[data-action="${action}"]`).click();
  await flushPromises();
}

describe('exam round edit / delete (#4970 E1)', () => {
  it('edits a round with the grade checkboxes and PATCHes grade_ids', async () => {
    const fetchMock = stubFetch();
    const wrapper = mount(ExamGradesPage, { props: { branchId: 1, userRole: 'director' }, attachTo: document.body });
    await flushPromises();
    await openMenu(wrapper, 'edit');
    const boxes = [...document.body.querySelectorAll('.exam-grade-option input')];
    expect(boxes).toHaveLength(12);
    expect(boxes[2].checked).toBe(true);
    boxes[3].click();
    await flushPromises();
    [...document.body.querySelectorAll('button')].find((b) => b.textContent.trim() === '儲存').click();
    await flushPromises();
    const patch = fetchMock.mock.calls.find(([, o]) => o?.method === 'PATCH');
    expect(patch[0]).toContain('/exam-rounds/7');
    const body = JSON.parse(patch[1].body);
    expect(body.grade_ids).toEqual([3, 4]);
    expect(body.subjects).toEqual([{ id: 11, max_score: 100, name: '國文', sort_order: 0 }]);
    wrapper.unmount();
  });

  it('asks for a reason only when the API says the round has scores', async () => {
    const fetchMock = stubFetch({ ok: false, status: 422, json: async () => ({ errors: { reason: ['此段考已有成績，刪除需填寫原因'] } }) });
    const wrapper = mount(ExamGradesPage, { props: { branchId: 1, userRole: 'director' }, attachTo: document.body });
    await flushPromises();
    await openMenu(wrapper, 'delete');
    expect(document.body.textContent).toContain('確定刪除「合成段考」嗎？');
    const confirm = () => [...document.body.querySelectorAll('button')].find((b) => b.textContent.trim() === '確定刪除');
    confirm().click();
    await flushPromises();
    expect(document.body.textContent).toContain('請填寫原因');
    const input = [...document.body.querySelectorAll('label')].find((l) => l.textContent.includes('請填寫原因')).querySelector('input');
    input.value = '開錯';
    input.dispatchEvent(new Event('input'));
    confirm().click();
    await flushPromises();
    const calls = fetchMock.mock.calls.filter(([, o]) => o?.method === 'DELETE');
    expect(JSON.parse(calls[1][1].body).reason).toBe('開錯');
    wrapper.unmount();
  });
});
