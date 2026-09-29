import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import AssessmentPage from '../../pages/AssessmentPage.vue';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/AssessmentPage.vue'), 'utf8');

describe('Assessment action accessibility', () => {
  it('renders native buttons with explicit non-submit types', async () => {
    const { wrapper } = await renderAssessment();
    try {
      const buttons = wrapper.findAll('button');
      expect(buttons.length).toBeGreaterThan(0);
      expect(buttons.every((button) => button.attributes('type') === 'button')).toBe(true);
      expect(buttons.some((button) => button.text() === '發布')).toBe(true);
    } finally { wrapper.unmount(); }
  });

  it('keeps the action controls available without changing their handlers', () => {
    expect(source).toContain('@click="loadAll"');
    expect(source).toContain('@click="createAssessment"');
    expect(source).toContain('@click="saveResult"');
    expect(source).toContain('@click="updateRemediation(action, \'completed\')"');
  });

  it('uses the shared dialog primitive for both assessment surfaces', () => {
    expect((source.match(/<AtDialog/g) || []).length).toBe(2);
    expect(source).toContain('title-id="assessment-create-title"');
    expect(source).toContain('title-id="assessment-result-title"');
  });
});

afterEach(() => vi.unstubAllGlobals());
async function renderAssessment(role = 'director', respond = null) {
  const fetchMock = vi.fn(async (input, options) => respond?.(String(input), options) || ({ ok: true, json: async () => ({ data:
    String(input).includes('/assessments?') ? [
      { id: 101, title: 'Synthetic draft', status: 'draft', max_score: 100 },
      { id: 102, title: 'Synthetic published', status: 'published', max_score: 100 },
    ] : [],
  }) }));
  vi.stubGlobal('fetch', fetchMock);
  const wrapper = mount(AssessmentPage, { props: { branchId: 1, userRole: role }, global: { stubs: { teleport: true } } });
  await flushPromises();
  return { wrapper, fetchMock };
}
it('cancelled director close sends no mutation from either presentation', async () => {
  const { wrapper, fetchMock } = await renderAssessment();
  vi.stubGlobal('confirm', vi.fn(() => false));
  try {
    const buttons = wrapper.findAll('button').filter((button) => button.text() === '關閉');
    expect(buttons).toHaveLength(2);
    for (const button of buttons) await button.trigger('click');
    await flushPromises();
    expect(fetchMock.mock.calls.filter(([, options]) => options?.method === 'POST')).toHaveLength(0);
  } finally { wrapper.unmount(); }
});
it('teacher has no director close action', async () => {
  const { wrapper } = await renderAssessment('teacher');
  try { expect(wrapper.findAll('button').filter((button) => button.text() === '關閉')).toHaveLength(0); }
  finally { wrapper.unmount(); }
});
it('desktop and mobile publish use the same existing endpoint and payload', async () => {
  const { wrapper, fetchMock } = await renderAssessment();
  try {
    const buttons = wrapper.findAll('button').filter((button) => button.text() === '發布');
    expect(buttons).toHaveLength(2);
    for (const button of buttons) { await button.trigger('click'); await flushPromises(); }
    const writes = fetchMock.mock.calls.filter(([, options]) => options?.method === 'POST');
    expect(writes).toHaveLength(2);
    expect(writes.every(([url, options]) => String(url).endsWith('/assessments/101/publish') && options.body === undefined)).toBe(true);
  } finally { wrapper.unmount(); }
});

function detailResponse(url, options) {
  let data;
  if (options?.method) return { ok: false, json: async () => ({ message: 'Synthetic rejected write' }) };
  if (url.endsWith('/students')) data = [{ student_class_id: 501, student_id: 201, name: 'Synthetic student' }];
  else if (url.endsWith('/questions')) data = [{ id: 601, question_type: 'short_answer', prompt: 'Synthetic question' }];
  else if (url.endsWith('/attempts')) data = [{ id: 801, status: 'submitted', student_id: 201, attempt_no: 1 }];
  else if (url.endsWith('/assessment-attempts/801')) data = { id: 801, status: 'submitted', questions: [], answers: [{ id: 901, status: 'needs_review', max_score: 10, prompt: 'Synthetic answer', position: 1 }] };
  else if (options?.method) return { ok: false, json: async () => ({ message: 'Synthetic rejected write' }) };
  else return null;
  return { ok: true, json: async () => ({ data }) };
}
async function openPublished(wrapper) {
  const row = wrapper.findAll('.assessment-desktop-table tbody tr').find((r) => r.text().includes('Synthetic published'));
  await row.find('button').trigger('click');
  await flushPromises();
}
it('result form retains exact student/course payload and exposes a rejected write', async () => {
  const { wrapper, fetchMock } = await renderAssessment('director', detailResponse);
  try {
    await openPublished(wrapper);
    await wrapper.find('.result-entry select').setValue('501');
    await wrapper.find('.result-entry input').setValue('82');
    await wrapper.findAll('button').find((b) => b.text() === '儲存結果').trigger('click');
    await flushPromises();
    const write = fetchMock.mock.calls.find(([, o]) => o?.method === 'POST');
    expect(String(write[0])).toMatch(/assessments\/102\/results$/);
    expect(JSON.parse(write[1].body)).toEqual({ student_id: 201, student_class_id: 501, score: 82, notes: null });
    expect(wrapper.find('.result-entry').text()).toContain('Synthetic rejected write');
  } finally { wrapper.unmount(); }
});
it('attempt creation retains student/course scope and exposes a rejected write', async () => {
  const { wrapper, fetchMock } = await renderAssessment('director', detailResponse);
  try {
    await openPublished(wrapper);
    await wrapper.find('.attempt-start select').setValue('501');
    await wrapper.findAll('button').find((b) => b.text() === '開始一次作答').trigger('click');
    await flushPromises();
    const write = fetchMock.mock.calls.find(([, o]) => o?.method === 'POST');
    expect(String(write[0])).toMatch(/assessments\/102\/attempts$/);
    expect(JSON.parse(write[1].body)).toEqual({ student_id: 201, student_class_id: 501 });
    expect(wrapper.find('.attempt-panel').text()).toContain('Synthetic rejected write');
  } finally { wrapper.unmount(); }
});
it('manual review keeps answer identity and score payload with visible failure', async () => {
  const { wrapper, fetchMock } = await renderAssessment('director', detailResponse);
  try {
    await openPublished(wrapper);
    await wrapper.findAll('button').find((b) => b.text() === '複核').trigger('click');
    await flushPromises();
    await wrapper.find('.review-row input').setValue('7');
    await wrapper.findAll('button').find((b) => b.text() === '完成人工複核').trigger('click');
    await flushPromises();
    const write = fetchMock.mock.calls.find(([, o]) => o?.method === 'POST');
    expect(String(write[0])).toMatch(/assessment-attempts\/801\/review$/);
    expect(JSON.parse(write[1].body)).toEqual({ reviews: [{ answer_id: 901, score: 7, review_note: null }] });
    expect(wrapper.find('.attempt-panel').text()).toContain('Synthetic rejected write');
  } finally { wrapper.unmount(); }
});
