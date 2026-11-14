import { afterEach, describe, it, expect, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

vi.mock('../../supabase', () => {
  const query = { select: () => query, eq: () => query, ilike: () => query, order: () => Promise.resolve({ data: [], error: null }), then: (resolve) => resolve({ data: [], error: null }) };
  return { supabase: { auth: { getSession: async () => ({ data: { session: { access_token: 'synthetic-token' } } }) }, from: () => query } };
});
import StudentsList from '../../pages/StudentsList.vue';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/StudentsList.vue'), 'utf8');
const nativeButtonTags = [...source.matchAll(/<button\b(?:[^>"']|"[^"]*"|'[^']*')*>/g)].map(([tag]) => tag);

describe('StudentsList row disclosure accessibility', () => {
  it('uses a named shared button for student import', () => {
    expect(source).not.toContain('<label class="button-outline">');
    expect(source).toMatch(/<Button type="button" variant="outline" :class="\[control\.page, phoneAction\]" aria-label="匯入名單（學生）" @click="openImportDialog">/);
    expect(source).toContain('aria-label="匯入名單（學生）"');
    expect(source).toContain('@click="openImportDialog"');
    expect(source).toContain('ref="importInput"');
    expect(source).toContain('class="tw:sr-only"');
    expect(source).toContain('@change="importStudents"');
    expect(source).toContain('const openImportDialog = () => {');
    expect(source).toContain('importInput.value?.click();');
  });

  it('offers a header-only import template without changing the import flow', () => {
    expect(source).toContain('aria-label="下載範例（學生名單匯入）"');
    expect(source).toContain('@click="downloadImportTemplate"');
    expect(source).toContain('const downloadImportTemplate = () => {');
    expect(source).toContain('學生姓名,年級,學校,手機');
    expect(source).toContain("link.download = '學生名單匯入範例.csv';");
  });

  it('makes the student row keyboard-operable with an explicit detail relationship', () => {
    expect(source).toContain('class="student-row"');
    expect(source).toContain('tabindex="0"');
    expect(source).toContain(':aria-expanded="expandedId === student.id"');
    expect(source).toContain(':aria-controls="`student-course-detail-${student.id}`"');
    expect(source).toContain('@keydown.enter.prevent="toggleExpand(student, $event)"');
    expect(source).toContain('@keydown.space.prevent="toggleExpand(student, $event)"');
    expect(source).toContain(':id="`student-course-detail-${student.id}`"');
  });

  it('keeps row-level keyboard handling away from selection and destructive actions', () => {
    expect(source).toContain('class="student-select-cell" @click.stop @keydown.stop');
    expect(source).toContain('@click.stop @keydown.stop class="action-cell"');
  });

  it('keeps every native management action explicit as a non-submit control', () => {
    expect(nativeButtonTags.length).toBeGreaterThan(20);
    expect(nativeButtonTags.filter((tag) => !/\btype\s*=\s*["']button["']/.test(tag))).toEqual([]);
  });

  it('provides an explicit course and enrollment disclosure button in each row', () => {
    expect(source).toContain('class="btn-course-disclosure"');
    expect(source).toContain('課程/購課');
    expect(source).toContain('收合課程與續課明細');
  });

  it('keeps the header on the page template: one primary last, the hidden file input a child of the actions', () => {
    expect(source).toContain('<PageHeader');
    expect(source).toContain('data-guide="students-header"');
    expect(source).toContain('data-guide="students-filters"');
    expect(source).toContain('<FilterChip :active="showHistoricalCourses" @click="toggleHistoricalCourses">');
    expect(source).toContain('<SelectionBar v-if="hasSelectedStudents"');
    expect(source).not.toContain('<AtPageHeader');
    expect(source).not.toContain('<AtFilterBar');
    const actions = source.slice(source.indexOf('<template #actions>'), source.indexOf('</PageHeader>'));
    const order = ['匯入名單</Button>', '下載範例</Button>', '跨分校身份</Button>', 'type="file"', '新增學生</Button>'].map((marker) => actions.indexOf(marker));
    expect(order.every((i) => i >= 0)).toBe(true);
    expect(order).toEqual([...order].sort((a, b) => a - b));
  });
});

describe('StudentsList header actions (mounted)', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('lists the actions in order, with the file input among them, and the import button opens the picker', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ data: [], total: 0 }) })));
    const stubs = {
      PageHeader: { template: '<header><slot name="actions" /></header>' },
      Button: { template: '<button type="button"><slot /></button>' },
    };
    const wrapper = shallowMount(StudentsList, { props: { branchId: 1 }, global: { stubs } });
    await flushPromises();
    const header = wrapper.find('header');
    const labels = [...header.element.children].map((el) => (el.tagName === 'INPUT' ? 'file-input' : el.textContent.trim()));
    expect(labels).toEqual(['匯入名單', '下載範例', '跨分校身份', 'file-input', '新增學生']);
    const input = header.find('input[type="file"]');
    expect(input.attributes('aria-hidden')).toBe('true');
    expect(input.attributes('tabindex')).toBe('-1');
    input.element.click = vi.fn();
    await header.find('[aria-label="匯入名單（學生）"]').trigger('click');
    expect(input.element.click).toHaveBeenCalledTimes(1);
    wrapper.unmount();
  });
});
