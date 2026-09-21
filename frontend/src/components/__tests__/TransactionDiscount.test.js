import { flushPromises, mount, shallowMount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

const { createUniversalClassSchedule } = vi.hoisted(() => ({ createUniversalClassSchedule: vi.fn() }));
vi.mock('../../lib/universalSchedulerApi', () => ({ createUniversalClassSchedule }));
vi.mock('../../lib/coursePackagesApi', () => ({ createMultiSubjectPackage: vi.fn() }));
vi.mock('../../lib/subjectsApi', () => ({ fetchSubjectOptions: vi.fn(async () => []) }));
vi.mock('../../lib/substituteApi.js', () => ({
  fetchTeacherAvailability: vi.fn(async () => []),
  previewTeacherLeaves: vi.fn(async () => ({ conflicts: [] })),
  batchSubstitute: vi.fn(async () => ({})),
  undoSubstitute: vi.fn(async () => ({})),
}));
vi.mock('../../supabase', () => ({
  supabase: { auth: { getSession: vi.fn(async () => ({ data: { session: null } })) } },
}));
import {
  calculateTransactionDiscountPreview,
  canApplyRenewalPreview,
  estimateMonthlyRenewalCharge,
  estimatePurchaseBatchCharge,
  getRenewalPreviewAmount,
  normalizeTransactionDiscount,
} from '../../lib/coursePricing.js';
import UniversalClassScheduler from '../UniversalClassScheduler.vue';
import SmartCalendar from '../../pages/SmartCalendar.vue';

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

  it('passes the finance gate through a mounted Smart Calendar scheduler', async () => {
    const mountCalendar = (userRole) => shallowMount(SmartCalendar, {
      props: { branchId: 0, userRole, userId: 1, initialIntent: 'quick-add' },
      global: {
        stubs: {
          UniversalClassScheduler: {
            props: { allowFinancialDiscount: Boolean },
            template: '<div data-testid="mounted-calendar-scheduler" :data-financial-discount="allowFinancialDiscount ? \'enabled\' : \'disabled\'" />',
          },
        },
      },
    });

    const director = mountCalendar('director');
    await flushPromises();
    expect(director.find('[data-testid="mounted-calendar-scheduler"]').exists()).toBe(true);
    expect(director.find('[data-testid="mounted-calendar-scheduler"]').attributes('data-financial-discount')).toBe('enabled');
    director.unmount();

    const teacher = mountCalendar('teacher');
    await flushPromises();
    expect(teacher.find('[data-testid="mounted-calendar-scheduler"]').exists()).toBe(true);
    expect(teacher.find('[data-testid="mounted-calendar-scheduler"]').attributes('data-financial-discount')).toBe('disabled');
    teacher.unmount();
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

  it('uses the backend canonical hourly total for purchase and monthly renewal previews', () => {
    const hourly = { Rate: 500, rate_unit: 'hour', SessionDuration: 120, Charge: 9999, monthly_sessions: 2 };
    expect(estimatePurchaseBatchCharge(hourly, 2)).toBe(2000);
    expect(estimatePurchaseBatchCharge({ ...hourly, SessionDuration: 90 }, 3)).toBe(2500);
    expect(estimateMonthlyRenewalCharge(hourly)).toBe(2000);
  });

  it('uses date-specific server renewal-preview totals for different weekly periods', () => {
    const shorterPeriod = { requested_end_date: '2032-02-28', billing: { amount_due: 1500 } };
    const longerPeriod = { requested_end_date: '2032-03-31', billing: { amount_due: 2250 } };
    expect(getRenewalPreviewAmount(shorterPeriod)).toBe(1500);
    expect(getRenewalPreviewAmount(longerPeriod)).toBe(2250);
    expect(getRenewalPreviewAmount(shorterPeriod)).not.toBe(getRenewalPreviewAmount(longerPeriod));
  });

  it('rejects an out-of-order renewal preview before it can overwrite the latest period', async () => {
    let resolveOld;
    let resolveNew;
    const oldResponse = new Promise((resolve) => { resolveOld = resolve; });
    const newResponse = new Promise((resolve) => { resolveNew = resolve; });
    const currentRequestId = 2;
    const applied = [];
    const apply = async (response, requestId, endDate) => {
      const preview = await response;
      if (canApplyRenewalPreview({
        requestId,
        currentRequestId,
        courseId: 7,
        currentCourseId: 7,
        requestedEndDate: endDate,
        currentEndDate: '2032-03-31',
      })) applied.push(getRenewalPreviewAmount(preview));
    };
    const old = apply(oldResponse, 1, '2032-02-28');
    const latest = apply(newResponse, 2, '2032-03-31');
    resolveOld({ billing: { amount_due: 1500 } });
    await old;
    expect(applied).toEqual([]);
    resolveNew({ billing: { amount_due: 2250 } });
    await latest;
    expect(applied).toEqual([2250]);
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
