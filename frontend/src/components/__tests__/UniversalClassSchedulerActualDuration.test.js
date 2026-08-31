import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../lib/subjectsApi', () => ({
  fetchSubjectOptions: vi.fn(async () => []),
}));
vi.mock('../../lib/universalSchedulerApi', () => ({
  createUniversalClassSchedule: vi.fn(async () => ({})),
}));
vi.mock('../../lib/coursePackagesApi', () => ({
  createMultiSubjectPackage: vi.fn(async () => ({})),
}));

import perfFlags from '../../lib/perfFlags';
import UniversalClassScheduler from '../UniversalClassScheduler.vue';

/**
 * RFC_NONSTANDARD_SESSION_DURATION_BILLING — 建課表單的扣堂方式選項。
 *
 * 這組測試最重要的一條：旗標關閉時，表單必須跟改動前一模一樣。
 */
async function mountScheduler() {
  const wrapper = mount(UniversalClassScheduler, {
    props: {
      branchId: 1,
      students: [{ id: 1, name: '測試學生' }],
      teachers: [{ id: 2, name: '測試老師', branch_ids: [1] }],
      rooms: [],
    },
    attachTo: document.body,
  });
  await flushPromises();
  return wrapper;
}

describe('UniversalClassScheduler — 依實際時長扣堂', () => {
  beforeEach(() => {
    perfFlags.ACTUAL_DURATION_DEDUCTION_ENABLED = false;
  });

  it('旗標關閉時完全看不到扣堂方式，也不會送出任何新欄位', async () => {
    const wrapper = await mountScheduler();

    expect(wrapper.find('.duration-basis-group').exists()).toBe(false);
    expect(wrapper.find('.coverage-preview').exists()).toBe(false);
    expect(wrapper.text()).not.toContain('依實際上課時長換算');
    expect(wrapper.text()).not.toContain('標準一堂時長');

    wrapper.unmount();
  });

  it('旗標關閉時，排課次數仍然綁定購買總堂數（原有規則不變）', async () => {
    const wrapper = await mountScheduler();
    const vm = wrapper.vm;

    vm.form.total_classes = 8;
    // 就算 deduction_basis 被塞成 actual_duration，旗標關閉時也不得生效。
    vm.form.deduction_basis = 'actual_duration';
    vm.form.planned_occurrences = 6;
    await flushPromises();

    expect(vm.isActualDuration).toBe(false);
    expect(vm.occurrenceTarget).toBe(8);
    expect(vm.durationCoverage).toBe(null);
    expect(vm.overageBlocksSubmit).toBe(false);

    wrapper.unmount();
  });

  it('月結開課日不在固定星期時，仍顯示開課日首堂並保留後續固定星期', async () => {
    const wrapper = await mountScheduler();
    const vm = wrapper.vm;

    vm.form.payment_type = 'monthly';
    vm.form.course_start_date = '2026-05-01';
    vm.form.end_date = '2026-05-31';
    vm.form.days_of_week = [4];
    vm.form.start_time = '18:00';
    vm.form.duration_hours = 2;
    await flushPromises();

    expect(vm.monthlySystemOccurrences.map((entry) => entry.ymd)).toEqual([
      '2026-05-01', '2026-05-07', '2026-05-14', '2026-05-21', '2026-05-28',
    ]);
    expect(vm.monthlyPreviewText).toContain('含開課日首堂');
    expect(vm.monthlyPreviewText).toContain('共 5 堂');

    wrapper.unmount();
  });

  it('旗標開啟後，選了依實際時長才會出現標準堂長與排課次數', async () => {
    perfFlags.ACTUAL_DURATION_DEDUCTION_ENABLED = true;
    const wrapper = await mountScheduler();

    expect(wrapper.find('.duration-basis-group').exists()).toBe(true);
    // 預設仍是「每次上課扣一堂」，不是預設換算。
    expect(wrapper.vm.form.deduction_basis).toBe('fixed_session');
    expect(wrapper.find('.coverage-preview').exists()).toBe(false);

    wrapper.vm.form.deduction_basis = 'actual_duration';
    await flushPromises();

    expect(wrapper.vm.isActualDuration).toBe(true);
    expect(wrapper.text()).toContain('這門課的標準一堂時長');
    expect(wrapper.text()).toContain('預計排課次數');

    wrapper.unmount();
  });

  it('排課次數可以與購買堂數不同（D6 解耦）', async () => {
    perfFlags.ACTUAL_DURATION_DEDUCTION_ENABLED = true;
    const wrapper = await mountScheduler();
    const vm = wrapper.vm;

    vm.form.deduction_basis = 'actual_duration';
    vm.form.total_classes = 8;
    vm.form.planned_occurrences = 6;
    await flushPromises();

    expect(vm.occurrenceTarget).toBe(6);
    expect(vm.safePlannedSessions).toBe(8);

    wrapper.unmount();
  });

  it('標準堂長是這門課自己的值：同一份排課會因標準不同而超額或有餘額', async () => {
    perfFlags.ACTUAL_DURATION_DEDUCTION_ENABLED = true;
    const wrapper = await mountScheduler();
    const vm = wrapper.vm;

    vm.form.deduction_basis = 'actual_duration';
    vm.form.total_classes = 8;
    vm.form.planned_occurrences = 6;
    vm.form.duration_hours = 3; // 每次 180 分鐘
    vm.form.days_of_week = [1, 2, 3, 4, 5, 6, 7];
    await flushPromises();

    // 標準 120：8 × 120 = 960 分鐘 < 6 × 180 = 1080 → 超額
    vm.form.standard_lesson_minutes = 120;
    await flushPromises();
    expect(vm.durationCoverage.entitlementMinutes).toBe(960);
    expect(vm.durationCoverage.scheduledMinutes).toBe(1080);
    expect(vm.durationCoverage.fullyCoveredOccurrences).toBe(5);
    expect(vm.durationCoverage.uncoveredMinutes).toBe(120);
    expect(vm.durationCoverage.uncoveredLessonEquivalent).toBe('1.00');
    expect(vm.needsOverageConfirmation).toBe(true);
    expect(vm.overageBlocksSubmit).toBe(true);

    // 同一份排課，標準改成 180：8 × 180 = 1440 分鐘 > 1080 → 有餘額
    vm.form.standard_lesson_minutes = 180;
    await flushPromises();
    expect(vm.durationCoverage.entitlementMinutes).toBe(1440);
    expect(vm.durationCoverage.fullyCoveredOccurrences).toBe(6);
    expect(vm.durationCoverage.uncoveredMinutes).toBe(0);
    expect(vm.durationCoverage.remainingLessonEquivalent).toBe('2.00');
    expect(vm.needsOverageConfirmation).toBe(false);
    expect(vm.overageBlocksSubmit).toBe(false);

    wrapper.unmount();
  });

  it('超額時未勾確認就擋住送出，勾了才放行', async () => {
    perfFlags.ACTUAL_DURATION_DEDUCTION_ENABLED = true;
    const wrapper = await mountScheduler();
    const vm = wrapper.vm;

    vm.form.deduction_basis = 'actual_duration';
    vm.form.standard_lesson_minutes = 120;
    vm.form.total_classes = 8;
    vm.form.planned_occurrences = 6;
    vm.form.duration_hours = 3;
    vm.form.days_of_week = [1, 2, 3, 4, 5, 6, 7];
    await flushPromises();

    expect(vm.overageBlocksSubmit).toBe(true);
    expect(wrapper.find('.coverage-overage').exists()).toBe(true);

    vm.form.overage_confirmed = true;
    await flushPromises();
    expect(vm.overageBlocksSubmit).toBe(false);

    wrapper.unmount();
  });

  it('每次上課的扣堂比例照這門課的標準換算，不是固定 1.5', async () => {
    perfFlags.ACTUAL_DURATION_DEDUCTION_ENABLED = true;
    const wrapper = await mountScheduler();
    const vm = wrapper.vm;

    vm.form.deduction_basis = 'actual_duration';
    vm.form.total_classes = 8;
    vm.form.planned_occurrences = 6;
    vm.form.duration_hours = 3; // 180 分鐘
    vm.form.days_of_week = [1, 2, 3, 4, 5, 6, 7];

    for (const [standard, expected] of [[120, '1.50'], [180, '1.00'], [90, '2.00'], [60, '3.00']]) {
      vm.form.standard_lesson_minutes = standard;
      await flushPromises();
      expect(vm.perOccurrenceLessonEquivalents).toEqual([{ minutes: 180, lessons: expected }]);
    }

    wrapper.unmount();
  });
});
