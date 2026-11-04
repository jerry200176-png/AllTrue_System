import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { mount, flushPromises } from '@vue/test-utils';
import { defineComponent, defineAsyncComponent, h, ref, watch, nextTick, onActivated, onDeactivated, KeepAlive } from 'vue';

const read = (p) => readFileSync(resolve(dirname(fileURLToPath(import.meta.url)), p), 'utf8');
const app = read('../../App.vue');

describe('App.vue keeps the 5 main pages alive', () => {
  it.each(['DirectorDashboard', 'CourseManagement', 'StudentsList', 'TuitionCollectionPage', 'SmartCalendar'])('%s is wrapped in KeepAlive', (name) => {
    expect(app).toMatch(new RegExp(`<KeepAlive><${name}\\s+v-if=[^\\n]*/></KeepAlive>`));
  });

  it('remembers and restores scroll per kept page', () => {
    expect(app).toContain('KEEP_ALIVE_PAGES');
    expect(app).toContain('pageScroll.set(prev');
  });

  it('every kept page watches its initial-* props and reloads on branch change', () => {
    const checks = {
      TuitionCollectionPage: ['() => props.initialTab', '() => props.branchId'],
      CourseManagement: ['props.initialStudentId', 'props.initialTeacherId', '() => props.branchId'],
      StudentsList: ['props.initialStudentId', '() => props.branchId'],
      SmartCalendar: ['() => props.initialTeacherId', '() => props.initialIntent', '() => props.branchId'],
    };
    for (const [page, needles] of Object.entries(checks)) {
      const src = read(`../../pages/${page}.vue`);
      for (const n of needles) expect(src, `${page} ${n}`).toContain(n);
    }
  });

  it('hidden pages drop their document listeners (activated/deactivated pairs)', () => {
    for (const page of ['CourseManagement', 'StudentsList', 'SmartCalendar']) {
      const src = read(`../../pages/${page}.vue`);
      expect(src, page).toContain('onDeactivated(');
      expect(src, page).toContain('onActivated(');
    }
  });
});

// Same pattern as App.vue: async page (like asyncPage()) + KeepAlive around a single v-if child.
const Page = defineComponent({
  props: { initialTab: { type: String, default: '' } },
  setup(props) {
    const search = ref('');
    const tab = ref('students');
    const activations = ref(0);
    watch(() => props.initialTab, (t) => { if (t) tab.value = t; }, { immediate: true });
    onActivated(() => { activations.value += 1; });
    onDeactivated(() => {});
    return () => h('div', [
      h('input', { class: 'search', value: search.value, onInput: (e) => { search.value = e.target.value; } }),
      h('span', { class: 'tab' }, tab.value),
    ]);
  },
});
const AsyncPage = defineAsyncComponent({ loader: () => Promise.resolve(Page) });

const Shell = (kept) => defineComponent({
  props: { active: String, initialTab: String },
  setup(props) {
    return () => {
      const child = props.active === 'tuition' ? h(AsyncPage, { initialTab: props.initialTab }) : null;
      return h('div', [kept ? h(KeepAlive, null, { default: () => child }) : child, props.active === 'other' ? h('p', 'other') : null]);
    };
  },
});

describe('KeepAlive + async page + v-if (App.vue pattern)', () => {
  it('keeps typed search text and tab across a switch away and back', async () => {
    const w = mount(Shell(true), { props: { active: 'tuition', initialTab: '' } });
    await flushPromises();
    await w.find('.search').setValue('abc');
    await w.setProps({ active: 'other' });
    expect(w.find('.search').exists()).toBe(false);
    await w.setProps({ active: 'tuition' });
    await flushPromises();
    expect(w.find('.search').element.value).toBe('abc');
  });

  it('control: without KeepAlive the state is lost', async () => {
    const w = mount(Shell(false), { props: { active: 'tuition', initialTab: '' } });
    await flushPromises();
    await w.find('.search').setValue('abc');
    await w.setProps({ active: 'other' });
    await w.setProps({ active: 'tuition' });
    await flushPromises();
    expect(w.find('.search').element.value).toBe('');
  });

  it('a changed initial-* prop still applies to the cached page', async () => {
    const w = mount(Shell(true), { props: { active: 'tuition', initialTab: '' } });
    await flushPromises();
    await w.setProps({ active: 'other' });
    await w.setProps({ active: 'tuition', initialTab: 'settled' });
    await nextTick();
    expect(w.find('.tab').text()).toBe('settled');
  });
});
