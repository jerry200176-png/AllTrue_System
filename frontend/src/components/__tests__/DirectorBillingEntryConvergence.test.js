import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';
import TuitionCollectionPage from '../../pages/TuitionCollectionPage.vue';
import { buildTuitionLedgerNav } from '../../lib/authoritativeMutationRoutes.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const courseManagement = readFileSync(resolve(__dirname, '../../pages/CourseManagement.vue'), 'utf8');
const studentsList = readFileSync(resolve(__dirname, '../../pages/StudentsList.vue'), 'utf8');

const alertRows = [
  { id: 6201, student_class_id: 6201, student_id: 501, student_name: '合成學生甲', subject: 'Math', payment_status: 'unpaid', days_until_settlement: 3 },
];

afterEach(() => { vi.unstubAllGlobals(); localStorage.clear(); });

async function mountTuition(props, rows = alertRows) {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'synthetic-token', user: { role: 'director' } }));
  vi.stubGlobal('fetch', vi.fn(async (input) => {
    const url = new URL(String(input), 'http://synthetic.local');
    const data = url.pathname === '/api/v1/alerts/tuition' ? rows : [];
    return { ok: true, json: async () => data };
  }));
  const wrapper = shallowMount(TuitionCollectionPage, { props: { branchId: 1, ...props } });
  await flushPromises();
  return wrapper;
}

describe('director billing M2: one authoritative entry (帳務中心 → 學生帳務檔)', () => {
  it('builds a ledger deep link with student and course ids', () => {
    expect(buildTuitionLedgerNav({ id: 6201, student_id: 501 })).toEqual({
      target: 'tuition-collect', studentId: 501, courseId: 6201, intent: 'ledger',
    });
  });

  it('opens the student billing file for a ledger deep link and still focuses the queue row', async () => {
    const wrapper = await mountTuition({ initialTab: 'ledger', initialStudentId: 501, initialCourseId: 6201 });
    try {
      const ledger = wrapper.findComponent({ name: 'AccountingLedgerModal' });
      expect(ledger.props('show')).toBe(true);
      expect(ledger.props('studentClassId')).toBe(6201);
      expect(ledger.props('reportId')).toBeNull();
      expect(wrapper.find('.tc-focus-context').text()).toContain('已定位：合成學生甲');
    } finally { wrapper.unmount(); }
  });

  it('opens the billing file even when the course has nothing pending in the queue', async () => {
    const wrapper = await mountTuition({ initialTab: 'ledger', initialStudentId: 777, initialCourseId: 9999 });
    try {
      const ledger = wrapper.findComponent({ name: 'AccountingLedgerModal' });
      expect(ledger.props('show')).toBe(true);
      expect(ledger.props('studentClassId')).toBe(9999);
      expect(wrapper.find('.tc-focus-context').text()).toContain('已直接打開學生帳務');
    } finally { wrapper.unmount(); }
  });

  it('keeps payment deep links on the queue row without opening the billing file', async () => {
    const wrapper = await mountTuition({ initialTab: 'unpaid', initialStudentId: 501, initialCourseId: 6201 });
    try {
      expect(wrapper.findComponent({ name: 'AccountingLedgerModal' }).props('show')).toBe(false);
      expect(wrapper.find('.tc-focus-context').text()).toContain('已定位：合成學生甲');
    } finally { wrapper.unmount(); }
  });

  it('course management no longer keeps its own invoice list; billing CTAs deep-link to 帳務中心', () => {
    expect(courseManagement).not.toContain('帳單與對帳紀錄');
    expect(courseManagement).not.toMatch(/openInvoiceModal|invoiceModalOpen/);
    expect(readFileSync(resolve(__dirname, '../../composables/course-management/useCourseRowActions.js'), 'utf8')).toContain('invoice: () => d.openTuitionLedger(c)');
    expect(courseManagement).toContain('openTuitionLedger(hc); closeActionMenu()');
    expect(courseManagement).toContain('@click="openTuitionLedger(row.course)"');
    expect(courseManagement).toContain('invoice: () => openTuitionLedger(c)');
  });

  it('students list no longer keeps its own monthly invoice list', () => {
    expect(studentsList).not.toContain('月結帳單記錄');
    expect(studentsList).not.toMatch(/openInvoiceModal|showInvoiceModal/);
    expect(studentsList).toContain('@click="openTuitionLedger(course)"');
  });
});
