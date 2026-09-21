import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const { createUniversalClassSchedule } = vi.hoisted(() => ({ createUniversalClassSchedule: vi.fn() }));
vi.mock('../../lib/universalSchedulerApi', () => ({ createUniversalClassSchedule }));
vi.mock('../../lib/coursePackagesApi', () => ({ createMultiSubjectPackage: vi.fn() }));
vi.mock('../../lib/subjectsApi', () => ({ fetchSubjectOptions: vi.fn(async () => []) }));
vi.mock('../../lib/substituteApi.js', () => ({ fetchTeacherAvailability: vi.fn(async () => []) }));
import {
  calculateTransactionDiscountPreview,
  normalizeTransactionDiscount,
} from '../../lib/coursePricing.js';
import UniversalClassScheduler from '../UniversalClassScheduler.vue';

const __dirname = dirname(fileURLToPath(import.meta.url));

describe('transaction discount preview', () => {
  const mountScheduler = (props = {}) => mount(UniversalClassScheduler, {
    props: {
      branchId: 1,
      students: [{ id: 1, name: '學生' }],
      teachers: [{ id: 2, name: '老師' }],
      rooms: [],
      ...props,
    },
    global: { stubs: {
      SearchableSelect: { template: '<select><slot /></select>' },
      TeacherAvailabilityPlanner: { template: '<div />' },
      CoursePaymentDateField: { template: '<input />' },
    } },
  });

  it('renders the financial panel only for an explicitly enabled mount', async () => {
    const hidden = mountScheduler();
    await flushPromises();
    expect(hidden.find('[data-testid="transaction-discount-panel"]').exists()).toBe(false);
    hidden.unmount();

    const enabled = mountScheduler({ allowFinancialDiscount: true });
    await flushPromises();
    expect(enabled.find('[data-testid="transaction-discount-panel"]').exists()).toBe(true);
    expect(enabled.vm.form.discount).toEqual({ type: 'NONE', value: '0', reason: '' });
    enabled.unmount();
  });

  it('gates every canonical scheduler mount and never sends calculated totals', () => {
    const app = readFileSync(resolve(__dirname, '../../App.vue'), 'utf8');
    const students = readFileSync(resolve(__dirname, '../../pages/StudentsList.vue'), 'utf8');
    const courseManagement = readFileSync(resolve(__dirname, '../../pages/CourseManagement.vue'), 'utf8');
    const smartCalendar = readFileSync(resolve(__dirname, '../../pages/SmartCalendar.vue'), 'utf8');
    expect(app).toContain(':allow-financial-discount="isDirector"');
    expect(students).toContain('allowFinancialDiscount: { type: Boolean, default: false }');
    expect(students).toContain(':allow-financial-discount="props.allowFinancialDiscount"');
    expect(students).not.toContain(':allow-financial-discount="true"');
    expect(courseManagement).toContain(':allow-financial-discount="allowFinancialDiscount"');
    expect(smartCalendar).toContain(':allow-financial-discount="allowFinancialDiscount"');
    expect(smartCalendar).toContain("['director', 'admin', 'super_admin'].includes(props.userRole)");
    expect(smartCalendar).toContain("props.userRole === 'teacher'");
    expect(students).not.toContain('original_amount: purchaseDiscountPreview');
    expect(courseManagement).not.toContain('original_amount:');
    expect(smartCalendar).not.toContain('final_amount:');
  });

  it('defaults to NONE and preserves the original total', () => {
    expect(calculateTransactionDiscountPreview(1000)).toMatchObject({
      type: 'NONE', discountAmount: 0, finalAmount: 1000, requiresReason: false,
    });
  });

  it('previews fixed, percentage, and 100% discounts with integer totals', () => {
    expect(calculateTransactionDiscountPreview(1001, { type: 'FIXED_AMOUNT', value: '200' })).toMatchObject({ discountAmount: 200, finalAmount: 801 });
    expect(calculateTransactionDiscountPreview(101, { type: 'PERCENTAGE', value: '12.5' })).toMatchObject({ discountAmount: 13, finalAmount: 88 });
    expect(calculateTransactionDiscountPreview(1000, { type: 'PERCENTAGE', value: '100' })).toMatchObject({ discountAmount: 1000, finalAmount: 0 });
  });

  it('does not create a discount payload for an invalid mode', () => {
    expect(normalizeTransactionDiscount({ type: 'coupon', value: 10 })).toEqual({ type: 'NONE', value: '0', reason: '' });
  });

  it('resets stale discount state instead of inheriting it into a new transaction', () => {
    expect(normalizeTransactionDiscount()).toEqual({ type: 'NONE', value: '0', reason: '' });
    expect(calculateTransactionDiscountPreview(800, { type: 'NONE', value: '0', reason: '' }))
      .toMatchObject({ discountAmount: 0, finalAmount: 800, requiresReason: false });
  });

  it('requires a reason for a non-zero discount payload', () => {
    expect(calculateTransactionDiscountPreview(800, { type: 'FIXED_AMOUNT', value: '100', reason: '' }))
      .toMatchObject({ type: 'FIXED_AMOUNT', discountAmount: 100, requiresReason: true });
  });
});
