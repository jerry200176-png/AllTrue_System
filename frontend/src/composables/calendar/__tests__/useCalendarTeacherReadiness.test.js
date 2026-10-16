import { afterEach, describe, expect, it, vi } from 'vitest';
import { computed, effectScope, ref } from 'vue';
import { flushPromises, shallowMount } from '@vue/test-utils';

vi.mock('../../../supabase', () => ({
  supabase: { auth: { getSession: vi.fn(async () => ({ data: { session: { access_token: 'synthetic-token' } } })) } },
}));
vi.mock('../../../lib/universalSchedulerApi', () => ({ createUniversalClassSchedule: vi.fn() }));
vi.mock('../../../lib/coursePackagesApi', () => ({ createMultiSubjectPackage: vi.fn() }));
vi.mock('../../../lib/subjectsApi', () => ({ fetchSubjectOptions: vi.fn(async () => []) }));
vi.mock('../../../lib/substituteApi.js', () => ({ fetchTeacherAvailability: vi.fn(async () => []) }));

import { useCalendarDataLoad } from '../useCalendarDataLoad.js';
import UniversalClassScheduler from '../../../components/UniversalClassScheduler.vue';
import { buildCreateTeacherOptions, resolveCreateTeacherId } from '../../../lib/courseTeacherOptions.js';

// #4157 review: campus-switch race for the SmartCalendar quick-create teacher seed.
// Campus 1 teachers: 7 (active). Campus 2 teachers: 21 (active), 22 (inactive).
const campusTeachers = {
  1: [{ id: 7, username: '一校老師', status: 'active', branch_ids: [1], branch_id: 1 }],
  2: [
    { id: 21, username: '二校老師', status: 'active', branch_ids: [2], branch_id: 2 },
    { id: 22, username: '二校停用', status: 'inactive', branch_ids: [2], branch_id: 2 },
  ],
  3: [{ id: 31, username: '三校老師', status: 'active', branch_ids: [3], branch_id: 3 }],
};

const deferred = () => {
  let resolve;
  const promise = new Promise((r) => { resolve = r; });
  return { promise, resolve };
};
const respond = (bid) => ({ ok: true, json: async () => campusTeachers[bid] });

const scopes = [];
function setup() {
  const branchId = ref(1);
  const pending = [];
  vi.stubGlobal('fetch', vi.fn((url) => {
    const bid = Number(new URL(url, 'http://x').searchParams.get('branch_id'));
    const held = deferred();
    pending.push({ bid, held });
    return held.promise;
  }));
  const scope = effectScope();
  scopes.push(scope);
  const api = scope.run(() => useCalendarDataLoad({
    branchId,
    userId: ref(1),
    isTeacher: () => false,
    viewMode: ref('week'),
    getCalendarDataFetchBoundsYmd: () => ({}),
  }));
  // SmartCalendar wiring: create options + seed resolver with the loaded flag.
  const schedulerTeachers = computed(() => buildCreateTeacherOptions(api.teachers.value));
  const seedFor = (teacherId) => resolveCreateTeacherId(teacherId, schedulerTeachers.value, { loaded: api.teachersLoaded.value });
  const answer = async (bid) => {
    const entry = pending.find((p) => p.bid === bid && !p.done);
    entry.done = true;
    entry.held.resolve(respond(bid));
    await flushPromises();
  };
  return { api, branchId, schedulerTeachers, seedFor, answer };
}

const mountScheduler = (teachers, initialTeacherId) => shallowMount(UniversalClassScheduler, {
  props: { branchId: 2, mode: 'create', initialTeacherId, initialDaysOfWeek: [2], students: [], teachers, rooms: [] },
  global: {
    stubs: {
      SearchableSelect: { template: '<select><slot /></select>' },
      TeacherAvailabilityPlanner: { template: '<div />' },
      CoursePaymentDateField: { template: '<input />' },
    },
  },
});

afterEach(() => {
  scopes.splice(0).forEach((s) => s.stop());
  vi.unstubAllGlobals();
});

describe('calendar teacher readiness across campus switch (#4157 review)', () => {
  it('keeps an active new-campus seed until the new teachers arrive, then selects it', async () => {
    const { api, branchId, schedulerTeachers, seedFor, answer } = setup();
    api.loadTeachers();
    await flushPromises();
    await answer(1);
    expect(api.teachersLoaded.value).toBe(true);

    branchId.value = 2;
    api.loadTeachers();
    // Readiness resets synchronously, before the new campus's courses could render.
    expect(api.teachersLoaded.value).toBe(false);
    await flushPromises();
    const seed = seedFor(21);
    expect(seed).toBe(21);
    const wrapper = mountScheduler(schedulerTeachers.value, seed);
    expect(wrapper.vm.form.teacher_id).toBe(21);

    await answer(2);
    expect(api.teachersLoaded.value).toBe(true);
    await wrapper.setProps({ teachers: schedulerTeachers.value });
    await flushPromises();
    expect(wrapper.vm.form.teacher_id).toBe(21);
    expect(seedFor(21)).toBe(21);
    wrapper.unmount();
  });

  it('still clears a deactivated new-campus seed once the new teachers arrive', async () => {
    const { api, branchId, schedulerTeachers, seedFor, answer } = setup();
    api.loadTeachers();
    await flushPromises();
    await answer(1);

    branchId.value = 2;
    api.loadTeachers();
    await flushPromises();
    const wrapper = mountScheduler(schedulerTeachers.value, seedFor(22));
    expect(wrapper.vm.form.teacher_id).toBe(22);

    await answer(2);
    await wrapper.setProps({ teachers: schedulerTeachers.value });
    await flushPromises();
    expect(wrapper.vm.form.teacher_id).toBe('');
    expect(seedFor(22)).toBe('');
    wrapper.unmount();
  });

  it('a stale campus response cannot mark ready or overwrite the newer campus options', async () => {
    const { api, branchId, seedFor, answer } = setup();
    branchId.value = 2;
    api.loadTeachers();
    await flushPromises();
    branchId.value = 3;
    api.loadTeachers();
    await flushPromises();

    await answer(2); // old campus answers first
    expect(api.teachersLoaded.value).toBe(false);
    expect(api.teachers.value).toEqual([]);
    expect(seedFor(31)).toBe(31);

    await answer(3);
    expect(api.teachersLoaded.value).toBe(true);
    expect(api.teachers.value.map((t) => t.id)).toEqual([31]);
    expect(seedFor(21)).toBe('');
  });
});
