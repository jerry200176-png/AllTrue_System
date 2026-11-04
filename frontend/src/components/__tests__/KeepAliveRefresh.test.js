import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { mount, flushPromises } from '@vue/test-utils';
import { defineComponent, h, ref, onActivated, KeepAlive } from 'vue';

const read = (p) => readFileSync(resolve(dirname(fileURLToPath(import.meta.url)), p), 'utf8');

describe('kept-alive pages refetch quietly on return (stale-while-revalidate)', () => {
  it.each([
    ['DirectorDashboard', 'loadData({ quiet: true })'],
    ['CourseManagement', 'quietRefreshNext = true; void loadCourses(pagination.value.page)'],
    ['StudentsList', 'loadStudents({ quiet: true })'],
    ['SmartCalendar', 'loadCourses({ quiet: true })'],
  ])('%s calls its loader with quiet on re-activation', (page, call) => {
    const src = read(`../../pages/${page}.vue`);
    expect(src).toContain(call);
    expect(src).toContain('onActivated(');
  });

  it('quiet loaders do not raise their loading flag', () => {
    expect(read('../../pages/DirectorDashboard.vue')).toContain('if (!quiet) dashboardLoading.value = true;');
    expect(read('../../pages/CourseManagement.vue')).toContain('if (!quiet) coursesLoading.value = true;');
    expect(read('../../pages/StudentsList.vue')).toContain('if (!quiet) studentsLoading.value = true;');
    expect(read('../../composables/calendar/useCalendarDataLoad.js')).toContain('if (!quiet) calendarLoading.value = true;');
  });

  // Same shape as the pages: skip first activation, then refetch without clearing rows.
  it('harness: refetch runs after reactivation and old rows stay until new ones arrive', async () => {
    let resolveFetch;
    let calls = 0;
    const Page = defineComponent({
      setup() {
        const rows = ref(['old']);
        let first = true;
        onActivated(() => {
          if (first) { first = false; return; }
          calls += 1;
          new Promise((r) => { resolveFetch = r; }).then(() => { rows.value = ['new']; });
        });
        return () => h('p', { class: 'rows' }, rows.value.join());
      },
    });
    const Shell = defineComponent({
      props: { show: Boolean },
      setup: (p) => () => h('div', [h(KeepAlive, null, { default: () => (p.show ? h(Page) : null) })]),
    });
    const w = mount(Shell, { props: { show: true } });
    await flushPromises();
    expect(calls).toBe(0);
    await w.setProps({ show: false });
    await w.setProps({ show: true });
    expect(calls).toBe(1);
    expect(w.find('.rows').text()).toBe('old');
    resolveFetch();
    await flushPromises();
    expect(w.find('.rows').text()).toBe('new');
  });
});

describe('cached-page regressions (Codex P2s)', () => {
  it('StudentsList: an explicit student jump outside the filtered list resets filters and reloads before focusing', () => {
    const src = read('../../pages/StudentsList.vue');
    const w = src.slice(src.indexOf('watch(() => [props.initialStudentId, props.initialCourseId, props.initialStudentIntent]'));
    const body = w.slice(0, w.indexOf('}, { immediate: true });'));
    expect(body).toContain('hasStudentFilters.value');
    expect(body).toContain("filters.value = { search: '', grade: '', status: 'active' };");
    expect(body.indexOf('await loadStudents()')).toBeGreaterThan(0);
    expect(body.indexOf('await loadStudents()')).toBeLessThan(body.indexOf('focusInitialStudent()'));
  });

  it('SmartCalendar: deactivating closes the print dialog', () => {
    const src = read('../../pages/SmartCalendar.vue');
    const d = src.slice(src.indexOf('onDeactivated(() => {'));
    expect(d.slice(0, d.indexOf('});'))).toContain('showCalendarPrint.value = false');
  });

  it('CourseManagement: todayYmd depends on a tick bumped on activation', () => {
    const src = read('../../pages/CourseManagement.vue');
    expect(src).toContain('const todayYmd = computed(() => (clockTick.value, toYmd(new Date())));');
    const a = src.slice(src.indexOf('onActivated(() => {'));
    expect(a.slice(0, 120)).toContain('clockTick.value += 1;');
  });

  it('harness: a computed with a tick recomputes after reactivation, one without stays stale', async () => {
    const { computed, ref: r } = await import('vue');
    let day = 'd1';
    const tick = r(0);
    const withTick = computed(() => (tick.value, day));
    const noTick = computed(() => day);
    expect([withTick.value, noTick.value]).toEqual(['d1', 'd1']);
    day = 'd2';
    tick.value += 1;
    expect([withTick.value, noTick.value]).toEqual(['d2', 'd1']);
  });

  it('CourseManagement: a dashboard drill-down is consumed on return, not only on mount', () => {
    const src = read('../../pages/CourseManagement.vue');
    const activated = src.slice(src.lastIndexOf('onActivated(() => {'));
    expect(activated).toContain('if (consumeTrustFocus()) { void loadCourses(1); return; }');
    expect(src).toMatch(/onMounted\(\(\) => \{\s+consumeTrustFocus\(\);/);
  });

  it('CourseManagement: the body scroll lock is owned once and only while the page is visible', () => {
    const src = read('../../pages/CourseManagement.vue');
    expect(src).toContain('const want = pageVisible && !!focusedStudentKey.value;');
    expect(src).toContain('watch(focusedStudentKey, syncScrollLock);');
    expect(src).not.toMatch(/if \(focusedStudentKey\.value\) (un)?lockScroll\(\)/);
  });

  it('CourseManagement: explicit student, teacher and drill-down jumps replace cached filters and focus', () => {
    const src = read('../../pages/CourseManagement.vue');
    expect(src).toMatch(/function applyExplicitNav\(patch\) \{\s+filters\.value = \{ name: '', class_type: '', teacher_name: '', teacher_id: '', course_status: '', \.\.\.patch \};\s+focusedStudentKey\.value = null;/);
    expect(src).toContain('applyExplicitNav({ name: name.slice(0, 40) });');
    expect(src).toContain("applyExplicitNav({ teacher_id: String(id), teacher_name:");
    expect(src).toContain('applyExplicitNav({ name: String(focus.student_name).slice(0, 40) });');
    expect(src).not.toContain('filters.value.teacher_id = String(id);');
  });
});

