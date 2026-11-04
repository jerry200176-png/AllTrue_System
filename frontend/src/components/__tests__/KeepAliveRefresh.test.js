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
