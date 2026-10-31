// Billing UX F-5a2: batch 回報 / 確認入帳, CSV and 上課明細 are reachable from the student list.
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import StudentBillingList from '../tuition/StudentBillingList.vue';

const ROWS = [
  { id: 11, student_id: 1, student_name: '甲', payment_status: 'unpaid', payable_status: 'invoiced', payable_outstanding: 3000, due_date: '2026-10-01' },
  { id: 21, student_id: 2, student_name: '乙', payment_status: 'pending_report', payable_status: 'invoiced', payable_outstanding: 1800, latest_payment_report_id: 5 },
  { id: 31, student_id: 3, student_name: '丙', payment_status: 'paid', payable_status: 'paid', payable_outstanding: 0 },
];
const COUNTS = { 1: { confirm: 0, report: 1 }, 2: { confirm: 1, report: 0 } };
const mountList = (props = {}) => mount(StudentBillingList, { props: { alertRows: ROWS, today: '2026-10-07', batchCounts: COUNTS, ...props } });

describe('student list batch toolbar (F-5a2)', () => {
  it('offers no checkbox, batch or export controls without batchCounts', () => {
    const w = mountList({ batchCounts: null });
    expect(w.find('[data-testid="sbl-bulk"]').exists()).toBe(false);
    expect(w.find('[data-testid="sbl-pick-1"]').exists()).toBe(false);
  });

  it('only rows with something to batch get a checkbox; counts and buttons follow the picks', async () => {
    const w = mountList();
    expect(w.find('[data-testid="sbl-pick-3"]').exists()).toBe(false);
    expect(w.find('[data-testid="sbl-batch-report"]').exists()).toBe(false);
    await w.get('[data-testid="sbl-pick-1"]').setValue(true);
    expect(w.get('[data-testid="sbl-picked-count"]').text()).toBe('已選 1 位');
    expect(w.get('[data-testid="sbl-batch-report"]').text()).toContain('1 筆');
    expect(w.get('[data-testid="sbl-batch-confirm"]').attributes('disabled')).toBeDefined();
    await w.get('[data-testid="sbl-batch-report"]').trigger('click');
    expect(w.emitted('batch')).toEqual([['report', [1]]]);
  });

  it('全選 picks every batchable student on screen and 取消選取 clears them', async () => {
    const w = mountList();
    await w.get('[data-testid="sbl-select-all"]').setValue(true);
    expect(w.get('[data-testid="sbl-picked-count"]').text()).toBe('已選 2 位');
    await w.get('[data-testid="sbl-batch-confirm"]').trigger('click');
    expect(w.emitted('batch')[0]).toEqual(['confirm', [1, 2]]);
    await w.get('[data-testid="sbl-clear-picked"]').trigger('click');
    expect(w.find('[data-testid="sbl-picked-count"]').exists()).toBe(false);
  });

  it('a student filtered off the screen is not part of the pick', async () => {
    const w = mountList();
    await w.get('[data-testid="sbl-select-all"]').setValue(true);
    await w.get('[data-testid="sbl-search"]').setValue('乙');
    expect(w.get('[data-testid="sbl-picked-count"]').text()).toBe('已選 1 位');
    await w.get('[data-testid="sbl-batch-confirm"]').trigger('click');
    expect(w.emitted('batch')[0]).toEqual(['confirm', [2]]);
  });

  it('exports CSV and opens 上課明細 for a contract', async () => {
    const w = mountList();
    await w.get('[data-testid="sbl-export"]').trigger('click');
    expect(w.emitted('export')).toEqual([[[1, 2, 3]]]);
    await w.get('[data-testid="sbl-search"]').setValue('乙');
    await w.get('[data-testid="sbl-export"]').trigger('click');
    expect(w.emitted('export')[1]).toEqual([[2]]); // only the students on screen
    await w.get('[data-testid="sbl-search"]').setValue('');
    await w.get('[data-testid="sbl-expand-1"]').trigger('click');
    await w.get('[data-testid="sbl-sessions-11"]').trigger('click');
    expect(w.emitted('sessions')).toEqual([[11]]);
    await w.setProps({ exportDisabled: true });
    expect(w.get('[data-testid="sbl-export"]').attributes('disabled')).toBeDefined();
  });
});

describe('deep-link preset filter (F-5-1)', () => {
  it('re-applies the same chip when the same link is followed again after the director changed it', async () => {
    const w = mountList({ presetFilter: { key: 'confirm' } });
    const shown = () => w.findAll('[data-testid^="sbl-row-"]').length;
    expect(shown()).toBe(1);
    await w.get('[data-testid="sbl-filter-all"]').trigger('click');
    expect(shown()).toBe(3);
    await w.setProps({ presetFilter: { key: 'confirm' } }); // a new object, same chip
    expect(shown()).toBe(1);
  });
});

describe('page wiring (F-5a2)', () => {
  const page = readFileSync(resolve(dirname(fileURLToPath(import.meta.url)), '../../pages/TuitionCollectionPage.vue'), 'utf8');
  it('reuses the table batch preview, submit functions and CSV/session handlers', () => {
    expect(page).toContain('@batch="onListBatch"');
    expect(page).toContain('exportCSV(rows.filter((r) => studentIds.includes(r.student_id)))');
    expect(page).toContain('watch(activeAccountingTab, () => { selectedIds.value = []; batchFromList = false; })');
    expect(page).toContain('openSessionDetail(r)');
    expect(page).toMatch(/function onListBatch[\s\S]{0,400}openBatchPreview\(\);/);
    // the list picks contracts with the table's own rules
    expect(page).toContain("['unpaid', 'partial', 'pending_reconciliation'].includes(r.payment_status) && r.payable_status === 'invoiced' && hasBatchAmount(r)");
    expect(page).toContain("r.payment_status === 'pending_report' && Boolean(r.latest_payment_report_id)");
    // 回報 fields moved into the preview dialog, and its submit still needs a date
    expect(page).toContain('data-testid="batch-report-fields"');
    expect(page).toContain("batchPreviewMode === 'report' && !batchForm.payment_date");
  });
});
