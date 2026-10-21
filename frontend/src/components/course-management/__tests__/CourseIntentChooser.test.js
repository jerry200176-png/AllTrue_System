// in-app #429 (GitHub #4331): one 「新增課程／續課」 entry with three intents that land in the
// existing flows, and one name (續課, Founder 2026-10-15 Q8=A) for adding lessons everywhere.
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import CourseIntentChooser from '../CourseIntentChooser.vue';
import PurchaseSessionsModal from '../PurchaseSessionsModal.vue';
import { COURSE_ENTRY_LABEL, COURSE_INTENTS, RENEW_LESSONS_LABEL, courseIntentEligibility, routeCourseIntent } from '../../../lib/courseIntent.js';
import { courseActions } from '../../../lib/courseActions.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const read = (p) => readFileSync(resolve(__dirname, p), 'utf8');
const two = [
  { id: 1, label: '數學／王老師', course: { id: 1 }, transferable: true },
  { id: 2, label: '英文／李老師', course: { id: 2 }, transferable: true },
];

describe('course intent chooser (#4331)', () => {
  it('全新課程 emits without a course', async () => {
    const w = mount(CourseIntentChooser, { props: { show: true, courses: two } });
    expect(w.find('h3').text()).toBe(COURSE_ENTRY_LABEL);
    await w.find('[data-testid="course-intent-new"]').trigger('click');
    expect(w.emitted('choose')).toEqual([[{ intent: 'new', course: null }]]);
  });

  it('續課 with several courses asks which one, then emits that course', async () => {
    const w = mount(CourseIntentChooser, { props: { show: true, courses: two } });
    await w.find('[data-testid="course-intent-renew"]').trigger('click');
    expect(w.emitted('choose')).toBeUndefined();
    expect(w.text()).toContain(`要${RENEW_LESSONS_LABEL}哪一門課`);
    await w.find('[data-testid="course-intent-course-2"]').trigger('click');
    expect(w.emitted('choose')).toEqual([[{ intent: 'renew', course: { id: 2 } }]]);
  });

  it('a single course skips the picker; no courses points back to 全新課程', async () => {
    const one = mount(CourseIntentChooser, { props: { show: true, courses: [two[0]] } });
    await one.find('[data-testid="course-intent-change"]').trigger('click');
    expect(one.emitted('choose')).toEqual([[{ intent: 'change', course: { id: 1 } }]]);

    const none = mount(CourseIntentChooser, { props: { show: true, courses: [] } });
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
    const w = mount(CourseIntentChooser, { props: { show: true, courses } });
    await w.find('[data-testid="course-intent-renew"]').trigger('click');
    expect(w.find('[data-testid="course-intent-course-3"]').exists()).toBe(false);
    expect(w.find('[data-testid="course-intent-course-1"]').text()).toContain('週二 16:00');
    expect(w.find('[data-testid="course-intent-course-2"]').text()).toContain('週五 18:00');
    await w.find('[data-testid="course-intent-back"]').trigger('click');
    await w.find('[data-testid="course-intent-change"]').trigger('click');
    expect(w.find('[data-testid="course-intent-course-3"]').exists()).toBe(false);

    const trialOnly = mount(CourseIntentChooser, { props: { show: true, courses: [courses[2]] } });
    await trialOnly.find('[data-testid="course-intent-renew"]').trigger('click');
    expect(trialOnly.emitted('choose')).toBeUndefined();
    expect(trialOnly.find('[data-testid="course-intent-empty"]').exists()).toBe(true);
  });

  it('does not offer transfer for monthly, package or unstarted courses', async () => {
    const base = { status: 'active', payment_type: 'session', sessions_purchased: 8, remaining_sessions: 4 };
    const courses = [
      { ...base, id: 1, payment_type: 'monthly' },
      { ...base, id: 2, PackageID: 7 },
      { ...base, id: 3, remaining_sessions: 8 },
    ].map(course => ({ id: course.id, course, ...courseIntentEligibility(course) }));
    const w = mount(CourseIntentChooser, { props: { show: true, courses } });
    await w.find('[data-testid="course-intent-change"]').trigger('click');
    expect(w.find('[data-testid="course-intent-empty"]').exists()).toBe(true);
    expect(w.emitted('choose')).toBeUndefined();
  });

  it('keeps an exhausted paid course renewable without offering transfer', async () => {
    const course = { id: 4, status: 'active', payment_type: 'session', payment_status: 'paid', sessions_purchased: 8, remaining_sessions: 0 };
    const eligibility = courseIntentEligibility(course);
    expect(eligibility).toMatchObject({ renewable: true, transferable: false });
    const w = mount(CourseIntentChooser, { props: { show: true, courses: [{ id: 4, course, ...eligibility }] } });
    await w.find('[data-testid="course-intent-renew"]').trigger('click');
    expect(w.emitted('choose')).toEqual([[{ intent: 'renew', course }]]);
  });

  it('monthly renewal does not describe a zero lesson balance', () => {
    expect(courseIntentEligibility({ status: 'active', payment_type: 'monthly', remaining_sessions: 0 }))
      .toMatchObject({ renewable: true, transferable: false, showRemainingSessions: false });
  });

  it('does not make closed courses renewable or transferable', () => {
    const base = { status: 'active', payment_type: 'session', sessions_purchased: 8, remaining_sessions: 4 };
    for (const state of [{ status: 'inactive' }, { Stop: 1 }, { closed_reason: 'waived' }]) {
      expect(courseIntentEligibility({ ...base, ...state })).toMatchObject({ renewable: false, transferable: false });
    }
  });

  it('without a student (課程查找 header) 續課 shows where the row button is', async () => {
    const w = mount(CourseIntentChooser, { props: { show: true } });
    await w.find('[data-testid="course-intent-renew"]').trigger('click');
    expect(w.find('[data-testid="course-intent-guide"]').text()).toContain(`「${RENEW_LESSONS_LABEL}」`);
    await w.find('[data-testid="course-intent-back"]').trigger('click');
    expect(w.findAll('.choice-card')).toHaveLength(COURSE_INTENTS.length);
  });

  it('routeCourseIntent runs only the matching wired handler', () => {
    const handlers = { new: vi.fn(), renew: vi.fn(), change: vi.fn() };
    expect(routeCourseIntent('renew', handlers, 'c')).toBe(true);
    expect(handlers.renew).toHaveBeenCalledWith('c');
    expect(handlers.new).not.toHaveBeenCalled();
    expect(routeCourseIntent('bogus', handlers)).toBe(false);
    expect(routeCourseIntent('change', { new: vi.fn() })).toBe(false);
  });
});

describe('one name for adding lessons (#4331)', () => {
  it('the actual renewal modal uses 續課 for the action and explanation', () => {
    const w = mount(PurchaseSessionsModal, { props: { show: true, form: { student_name: '合成學生', subject: 'Math', sessions: 8 } } });
    expect(w.find('button.primary').text()).toBe('確認續課');
    expect(w.find('.modal-note').text()).toContain('續課會建立');
    expect(w.text()).not.toContain('加購');
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
    ];
    for (const f of files) {
      const src = read(f).replace(/^\s*\/\/.*$/gm, '');
      for (const old of ["'加購堂數'", "'續報加購'", "'再次續報加購'", "'續約／加購'", '>加購堂數<']) {
        expect(src, `${f} still has ${old}`).not.toContain(old);
      }
    }
    expect(read('../../../pages/CourseManagement.vue')).toContain('return RENEW_LESSONS_LABEL;');
    expect(read('../../../pages/StudentsList.vue')).toContain('{{ COURSE_ENTRY_LABEL }}');
  });

  it('學生管理 routes each intent to the existing flow', () => {
    const src = read('../../../pages/StudentsList.vue');
    expect(src).toContain('new: () => openAddCourse(student)');
    expect(src).toContain('renew: () => openAddSessionsForCourse(course)');
    expect(src).toContain("buildCourseMgmtOpsNav(course, { intent: 'transfer' })");
    const cm = read('../../../pages/CourseManagement.vue');
    expect(cm).toContain("props.initialCourseIntent === 'transfer'");
    // 換科目／老師／時段 = the row 「轉課」 (CourseTransferModal), not the records-only TransferSessionsModal.
    expect(cm).toMatch(/pendingTransferCourseId\.value = 0;[\s\S]{0,200}editCourse\(course, \{ openModal: false \}\); showCourseTransfer\.value = true;/);
    expect(src).toContain('courseIntentEligibility(course)');
  });
});
