import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';

vi.mock('../../supabase', () => ({
  supabase: { auth: { getSession: async () => ({ data: { session: { access_token: 'synthetic-token' } } }) } },
}));
import StudentsList from '../../pages/StudentsList.vue';
import ActionMenu from '../ActionMenu.vue';

// Student table rows (issue 4989, 學生管理 slice 2): the row's colour meanings, names, checkbox and menu are presentation that
// must not drift. Every case mounts the page with the row parts rendered for real (shallowMount stubs them by default).
afterEach(() => vi.unstubAllGlobals());

const STUDENTS = [
  { id: 2001, name: '合成學生', grade: 'J1', school: '測試國中', parent_name: '測試家長', rfid: 'TEST2001', status: 'active', notes: '', line_bound: true },
  { id: 2002, name: '暫停學生', grade: 'J2', school: '', parent_name: '', rfid: '', status: 'paused', notes: '', line_bound: false },
  { id: 2003, name: '已畢業生', grade: 'J3', school: '', parent_name: '', rfid: '', status: 'graduated', notes: '要補交資料', line_bound: false },
  { id: 2004, name: '轉校生', grade: 'J1', school: '', parent_name: '', rfid: '', status: 'transferred', notes: '', line_bound: false },
];
const course = (id, studentId, remaining) => ({
  id, StudentID: studentId, student_id: studentId, subject: 'Math', subject_name: '數學', status: 'active', payment_type: 'session', payment_status: 'paid',
  class_type: 'one_on_one', sessions_purchased: 20, remaining_sessions: remaining,
});
// 2001 has a healthy course, 2002 has a nearly used-up one.
const COURSES = [course(7001, 2001, 18), course(7002, 2002, 1)];

async function mountRows() {
  vi.stubGlobal('fetch', vi.fn(async (input) => {
    const url = String(input);
    const body = url.includes('/student-classes') ? { data: COURSES }
      : url.includes('/students?') ? { data: STUDENTS, total: STUDENTS.length }
        : url.includes('/class-sessions') ? { byClass: {} }
          : { data: [] };
    return { ok: true, json: async () => body };
  }));
  const wrapper = shallowMount(StudentsList, {
    props: { branchId: 1 },
    global: { stubs: { StatusBadge: false, ActionMenu: false }, renderStubDefaultSlot: true },
  });
  await flushPromises();
  return wrapper;
}
const rowOf = (wrapper, id) => wrapper.find(`[data-testid="student-row"][data-student-id="${id}"]`);

describe('student table rows', () => {
  it('shows a low 堂數 in danger and a healthy one neutral, by the data tone and the danger text colour', async () => {
    const wrapper = await mountRows();
    try {
      const low = rowOf(wrapper, 2002).find('[data-testid="student-subject-chip"]');
      const fine = rowOf(wrapper, 2001).find('[data-testid="student-subject-chip"]');
      expect(low.attributes('data-tone')).toBe('danger');
      expect(low.classes().join(' ')).toContain('--ds-danger-text');
      expect(low.text()).toContain('數學');
      // not colour alone: a reader of the row is also told the course is nearly used up
      expect(low.text()).toContain('即將用完');
      expect(fine.attributes('data-tone')).toBe('neutral');
      expect(fine.classes().join(' ')).not.toContain('--ds-danger-text');
      expect(fine.text()).not.toContain('即將用完');
    } finally {
      wrapper.unmount();
    }
  });

  it('gives each non-active status one badge: paused warning, graduated and transferred neutral, active none', async () => {
    const wrapper = await mountRows();
    try {
      const badge = (id) => rowOf(wrapper, id).find('[data-slot="status-badge"]');
      expect(rowOf(wrapper, 2001).findAll('[data-slot="status-badge"]')).toHaveLength(0);
      expect(badge(2002).attributes('data-tone')).toBe('warning');
      expect(badge(2002).text()).toBe('暫停中');
      expect(badge(2003).attributes('data-tone')).toBe('neutral');
      expect(badge(2003).text()).toBe('已畢業');
      expect(badge(2004).attributes('data-tone')).toBe('neutral');
      expect(badge(2004).text()).toBe('已轉校');
      // the old avatar initial carried the status by colour alone; the name cell is now the name and the badge only
      expect(rowOf(wrapper, 2002).find('[data-testid="student-name-cell"]').text()).toBe('暫停學生暫停中');
    } finally {
      wrapper.unmount();
    }
  });

  it('keeps a name on one line, with 年級・學校 as its phone-only second line', async () => {
    const wrapper = await mountRows();
    try {
      const cell = rowOf(wrapper, 2001).find('[data-testid="student-name-cell"]');
      const name = cell.findAll('span').find((s) => s.text() === '合成學生');
      expect(name.classes()).toContain('tw:whitespace-nowrap');
      const second = rowOf(wrapper, 2001).findAll('td')[2].findAll('span').find((s) => s.text().includes('・'));
      expect(second.text()).toBe('國中一年級 (J1)・測試國中');
      expect(second.classes()).toContain('tw:md:hidden');
    } finally {
      wrapper.unmount();
    }
  });

  it('names each row checkbox 選取 + the name and gives the header one the mixed state', async () => {
    const wrapper = await mountRows();
    try {
      // shallowMount keeps the checkbox's props, so the wiring (name, checked state) is read from them
      const all = () => wrapper.findComponent('[data-testid="student-select-all"]');
      expect(all().attributes('aria-label')).toBe('全選學生');
      expect(all().props('modelValue')).toBe(false);
      const pick = (id) => rowOf(wrapper, id).findComponent('[data-testid="student-select"]');
      expect(pick(2001).attributes('aria-label')).toBe('選取 合成學生');
      expect(pick(2002).attributes('aria-label')).toBe('選取 暫停學生');
      pick(2001).vm.$emit('update:modelValue', true);
      await flushPromises();
      expect(pick(2001).props('modelValue')).toBe(true);
      expect(pick(2002).props('modelValue')).toBe(false);
      expect(all().props('modelValue')).toBe('indeterminate');
      expect(wrapper.find('[data-testid="students-bulk-bar"]').exists()).toBe(true);
      // picking a checkbox does not open the row
      expect(rowOf(wrapper, 2001).attributes('aria-expanded')).toBe('false');
      all().vm.$emit('update:modelValue', true);
      await flushPromises();
      expect(all().props('modelValue')).toBe(true);
      expect(pick(2002).props('modelValue')).toBe(true);
    } finally {
      wrapper.unmount();
    }
  });

  it('offers one visible action, 課程/購課, that opens the row, and the rest in a menu ending with a danger 刪除學生', async () => {
    const confirmMock = vi.fn(() => false);
    vi.stubGlobal('confirm', confirmMock);
    const wrapper = await mountRows();
    try {
      const row = rowOf(wrapper, 2001);
      const buttons = row.findAll('button').filter((b) => !['student-select', 'student-row-toggle', 'am__trigger'].some((k) => b.attributes('data-testid') === k || b.classes().includes(k)));
      expect(buttons.map((b) => b.attributes('data-testid'))).toEqual(['student-course-disclosure']);
      const open = row.find('[data-testid="student-course-disclosure"]');
      expect(open.text()).toBe('課程/購課');
      await open.trigger('click');
      await flushPromises();
      expect(rowOf(wrapper, 2001).attributes('aria-expanded')).toBe('true');
      expect(rowOf(wrapper, 2001).find('[data-testid="student-course-disclosure"]').text()).toBe('收合');

      const menu = rowOf(wrapper, 2001).findComponent(ActionMenu);
      const items = menu.props('groups').flatMap((g) => g.items);
      expect(items.map((i) => i.label)).toEqual(['編輯', '學習檔案', '刪除學生']);
      expect(items.map((i) => Boolean(i.danger))).toEqual([false, false, true]);
      expect(menu.props('label')).toBe('更多操作：合成學生');
      menu.vm.$emit('select', 'delete');
      await flushPromises();
      expect(confirmMock).toHaveBeenCalledTimes(1);
      expect(confirmMock.mock.calls[0][0]).toContain('合成學生');
    } finally {
      wrapper.unmount();
    }
  });

  it('writes RFID and LINE as plain text, with 未綁定 on one line in the secondary colour', async () => {
    const wrapper = await mountRows();
    try {
      const bound = rowOf(wrapper, 2001).findAll('td')[6];
      expect(bound.text()).toContain('TEST2001');
      expect(bound.text()).toContain('LINE');
      const unbound = rowOf(wrapper, 2002).findAll('td')[6].findAll('span').find((s) => s.text() === '未綁定');
      expect(unbound.classes()).toEqual(expect.arrayContaining(['tw:whitespace-nowrap', 'tw:text-tpl-secondary']));
    } finally {
      wrapper.unmount();
    }
  });

  it('keeps the production-run hooks: row id, aria-expanded, controls and the detail row id', async () => {
    const wrapper = await mountRows();
    try {
      const row = rowOf(wrapper, 2001);
      expect(row.element.tagName).toBe('TR');
      expect(row.attributes('tabindex')).toBe('0');
      expect(row.attributes('aria-controls')).toBe('student-course-detail-2001');
      await row.trigger('click');
      await flushPromises();
      expect(wrapper.find('#student-course-detail-2001').exists()).toBe(true);
    } finally {
      wrapper.unmount();
    }
  });

  it('runs the 編輯 and 學習檔案 handlers from the menu', async () => {
    const wrapper = await mountRows();
    try {
      const menu = () => rowOf(wrapper, 2001).findComponent(ActionMenu);
      expect(wrapper.find('#student-modal-title').exists()).toBe(false);
      menu().vm.$emit('select', 'edit');
      await flushPromises();
      expect(wrapper.find('#student-modal-title').text()).toBe('編輯學生');
      expect(wrapper.find('.modal-overlay input').element.value).toBe('合成學生');
      const timeline = () => wrapper.findComponent({ name: 'StudentLearningTimeline' });
      expect(timeline().props('show')).toBe(false);
      menu().vm.$emit('select', 'timeline');
      await flushPromises();
      expect(timeline().props('show')).toBe(true);
      expect(timeline().props('studentName')).toBe('合成學生');
    } finally {
      wrapper.unmount();
    }
  });

  it('gives the phone expand control a name and aria-expanded that follow the row', async () => {
    const wrapper = await mountRows();
    try {
      const toggle = () => rowOf(wrapper, 2001).find('[data-testid="student-row-toggle"]');
      expect(toggle().element.tagName).toBe('BUTTON');
      expect(toggle().attributes('aria-label')).toBe('展開課程：合成學生');
      expect(toggle().attributes('aria-expanded')).toBe('false');
      expect(toggle().attributes('aria-controls')).toBe('student-course-detail-2001');
      await toggle().trigger('click');
      await flushPromises();
      expect(toggle().attributes('aria-label')).toBe('收合課程：合成學生');
      expect(toggle().attributes('aria-expanded')).toBe('true');
    } finally {
      wrapper.unmount();
    }
  });

  it('uses the shared stackRow / stackCell sets and a visible focus style on the row', () => {
    const source = readFileSync(resolve(dirname(fileURLToPath(import.meta.url)), '../../pages/StudentsList.vue'), 'utf8');
    expect(source).toContain(':class="[dataTable.stackRow, gridCols,');
    expect(source).toContain('dataTable.stackCell');
    expect(source).not.toMatch(/const (rowGrid|cell0) =/);
    const row = source.slice(source.indexOf('data-testid="student-row"') - 40, source.indexOf('data-testid="student-row"') + 600);
    expect(row).toContain('tw:focus-visible:outline-2');
    expect(row).toContain('tw:focus-visible:outline-ring');
  });

  it('paints the check and minus icons above the drawn checkbox box', () => {
    // The 20px box is a ::before (absolute); the shared CheckboxIndicator is not positioned, so without these two classes
    // the primary-filled box covers the icon and checked / mixed look the same as a solid square.
    const source = readFileSync(resolve(dirname(fileURLToPath(import.meta.url)), '../../pages/StudentsList.vue'), 'utf8');
    const checkBox = source.match(/const checkBox = "([^"]*)"/)[1];
    expect(checkBox).toContain('tw:before:absolute');
    expect(checkBox).toContain('tw:[&>[data-slot=checkbox-indicator]]:relative');
    expect(checkBox).toContain('tw:[&>[data-slot=checkbox-indicator]]:z-10');
  });
});
