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
async function renderAssessment(role = 'director') {
  const fetchMock = vi.fn(async (input) => ({ ok: true, json: async () => ({ data:
    String(input).includes('/assessments?') ? [
      { id: 101, title: 'Synthetic draft', status: 'draft', max_score: 100 },
      { id: 102, title: 'Synthetic published', status: 'published', max_score: 100 },
    ] : [],
  }) }));
  vi.stubGlobal('fetch', fetchMock);
  const wrapper = mount(AssessmentPage, { props: { branchId: 1, userRole: role } });
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
