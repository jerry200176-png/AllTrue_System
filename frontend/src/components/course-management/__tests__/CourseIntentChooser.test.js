// in-app #429 (GitHub #4331): one 「新增課程／續課」 entry with three intents that land in the
// existing flows, and one name (續課, Founder 2026-10-15 Q8=A) for adding lessons everywhere.
import { afterEach, describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { readFileSync } from 'node:fs';
import CourseIntentChooser from '../CourseIntentChooser.vue';
import PurchaseSessionsModal from '../PurchaseSessionsModal.vue';
import { COURSE_ENTRY_LABEL, COURSE_INTENTS, RENEW_LESSONS_LABEL, courseIntentEligibility, routeCourseIntent } from '../../../lib/courseIntent.js';
import { courseActions } from '../../../lib/courseActions.js';

const read = (p) => readFileSync(new URL(p, import.meta.url), 'utf8');
const mountedChoosers = [];
const mountChooser = (options) => {
  const wrapper = mount(CourseIntentChooser, { ...options, global: { stubs: { teleport: true } } });
  mountedChoosers.push(wrapper);
  return wrapper;
};
afterEach(() => { mountedChoosers.splice(0).forEach(w => w.unmount()); });
const two = [
  { id: 1, label: '數學／王老師', course: { id: 1 }, transferable: true },
  { id: 2, label: '英文／李老師', course: { id: 2 }, transferable: true },
];

describe('course intent chooser (#4331)', () => {
  it('contains keyboard focus, handles Escape and restores the opener', async () => {
    const opener = document.createElement('button');
    document.body.append(opener);
    opener.focus();
    const w = mountChooser({ attachTo: document.body, props: { show: false, courses: two } });
    try {
      await w.setProps({ show: true });
      await nextTick();
      expect(document.activeElement).toBe(w.find('[data-testid="course-intent-new"]').element);
      await w.find('[data-testid="course-intent-renew"]').trigger('click');
      await nextTick();
      expect(document.activeElement).toBe(w.find('[data-testid="course-intent-course-1"]').element);
      const panel = w.find('[role="dialog"]');
      const buttons = panel.findAll('button');
      buttons.at(-1).element.focus();
      await buttons.at(-1).trigger('keydown', { key: 'Tab' });
      expect(document.activeElement).toBe(buttons[0].element);
      await buttons[0].trigger('keydown', { key: 'Tab', shiftKey: true });
      expect(document.activeElement).toBe(buttons.at(-1).element);
      await panel.trigger('keydown', { key: 'Escape' });
      expect(w.emitted('close')).toHaveLength(1);
      await w.setProps({ show: false });
      expect(document.activeElement).toBe(opener);
    } finally { w.unmount(); opener.remove(); }
  });

  it('全新課程 emits without a course', async () => {
    const w = mountChooser({ props: { show: true, courses: two } });
    expect(w.find('h2').text()).toBe(COURSE_ENTRY_LABEL);
    await w.find('[data-testid="course-intent-new"]').trigger('click');
    expect(w.emitted('choose')).toEqual([[{ intent: 'new', course: null }]]);
  });

  it('a single course skips the picker; no courses points back to 全新課程', async () => {
    const one = mountChooser({ props: { show: true, courses: [two[0]] } });
    await one.find('[data-testid="course-intent-change"]').trigger('click');
    expect(one.emitted('choose')).toEqual([[{ intent: 'change', course: { id: 1 } }]]);

    const none = mountChooser({ props: { show: true, courses: [] } });
    await none.find('[data-testid="course-intent-renew"]').trigger('click');
    expect(none.find('[data-testid="course-intent-empty"]').text()).toContain('全新課程');
    expect(none.emitted('choose')).toBeUndefined();
  });

  it('parallel same-subject/teacher contracts are told apart, and 續課 hides non-renewable courses (#4358 review)', async () => {
    const courses = [
      { id: 1, label: '數學／王老師', detail: '週二 16:00 · 剩 2 堂', course: { id: 1 }, transferable: true },
      { id: 2, label: '數學／王老師', detail: '週五 18:00 · 剩 8 堂', course: { id: 2 }, transferable: true },
      { id: 3, label: '英文／李老師', detail: '試聽', course: { id: 3 }, renewable: false },
    ];
    const w = mountChooser({ props: { show: true, courses } });
    await w.find('[data-testid="course-intent-renew"]').trigger('click');
    expect(w.find('[data-testid="course-intent-course-3"]').exists()).toBe(false);
    expect(w.find('[data-testid="course-intent-course-1"]').text()).toContain('週二 16:00');
    expect(w.find('[data-testid="course-intent-course-2"]').text()).toContain('週五 18:00');
    await w.find('[data-testid="course-intent-course-2"]').trigger('click');
    expect(w.emitted('choose')).toEqual([[{ intent: 'renew', course: { id: 2 } }]]);
    await w.find('[data-testid="course-intent-back"]').trigger('click');
    await w.find('[data-testid="course-intent-change"]').trigger('click');
    expect(w.find('[data-testid="course-intent-course-3"]').exists()).toBe(false);

  });

  it.each([
    ['exhausted', { remaining_sessions: 0 }, true, false],
    ['monthly', { payment_type: 'monthly' }, true, false],
    ['monthly package', { payment_type: 'monthly', PackageID: 9 }, false, false],
    ['session package', { PackageID: 9 }, true, false],
    ['trial', { class_type: 'trial' }, false, false],
    ['inactive', { status: 'inactive' }, false, false],
    ['stopped', { Stop: 1 }, false, false],
    ['closed', { closed_reason: 'waived' }, false, false],
  ])('%s course eligibility', (_name, override, renewable, transferable) => {
    expect(courseIntentEligibility({ status: 'active', payment_type: 'session', sessions_purchased: 8, remaining_sessions: 4, ...override }))
      .toMatchObject({ renewable, transferable });
  });

  it.each(['renew', 'change'])('context-free %s returns guidance to the course list', async (intent) => {
    const w = mountChooser({ props: { show: true } });
    await w.find(`[data-testid="course-intent-${intent}"]`).trigger('click');
    expect(w.emitted('browse')).toEqual([[intent]]);
  });

  it('rejects unknown or unwired intents (valid routing is exercised by the page tests)', () => {
    expect(routeCourseIntent('bogus', {})).toBe(false);
    expect(routeCourseIntent('change', { new: vi.fn() })).toBe(false);
  });
});

describe('one name for adding lessons (#4331)', () => {
  it('the actual renewal modal uses 續課 for the action and explanation', () => {
    const w = mount(PurchaseSessionsModal, { props: { show: true, form: { student_name: '合成學生', subject: 'Math', sessions: 8 } } });
    expect(w.find('button.primary').text()).toBe('確認續課');
    expect(w.find('.modal-note').text()).toContain('續課會建立');
    expect(w.text()).not.toContain('加購');
    expect(COURSE_INTENTS.find(i => i.id === 'renew').hint).toContain('原合約可能自動結案');
    expect(COURSE_INTENTS.find(i => i.id === 'renew').hint).toContain('月結制延長期別');
  });

  it('the row/⋯ menu default is the shared constant', () => {
    const { groups } = courseActions({ status: 'active' }, {});
    const billing = groups.find((g) => g.id === 'billing');
    expect(billing.items[0]).toEqual({ id: 'purchase', label: RENEW_LESSONS_LABEL });
  });

  it('call sites use the constant and no longer show the old names', () => {
    const files = [
      '../../../pages/CourseManagement.vue',
      '../../../pages/StudentsList.vue',
      '../../../lib/courseActions.js',
      '../CourseManager.vue',
      '../PurchaseSessionsModal.vue',
      '../../EnrollmentConflictDecisionModal.vue',
    ];
    for (const f of files) {
      const src = read(f).replace(/^\s*\/\/.*$/gm, '');
      for (const old of ["'加購堂數'", "'續報加購'", "'再次續報加購'", "'續約／加購'", '>加購堂數<', '去加購', '原加購規則', '加購失敗', '已建立加購批次', '加購／續報堂數']) {
        expect(src, `${f} still has ${old}`).not.toContain(old);
      }
    }
    expect(read('../../../pages/CourseManagement.vue')).toContain('return RENEW_LESSONS_LABEL;');
    expect(read('../../../pages/StudentsList.vue')).toContain('{{ COURSE_ENTRY_LABEL }}');
  });

});
