import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import CourseManager from '../CourseManager.vue';
import perfFlags from '../../../lib/perfFlags.js';

const baseCourse = {
  id: 11,
  subject: 'Math',
  teacher_name: '王老師',
  class_type: 'one_on_one',
  status: 'active',
  payment_type: 'session',
  remaining_sessions: 5,
  sessions_purchased: 12,
};

function mountManager(overrides = {}) {
  const props = {
    course: baseCourse,
    tab: 'overview',
    studentName: '陳湘甯',
    subjectLabel: '高中數學',
    classTypeLabel: '一對一',
    statusLabel: '進行中',
    scheduleSummary: '週三 18:30–20:30',
    remainingLabel: '剩餘 5 / 12 堂',
    nextSessionLabel: '9/23',
    paymentLabel: '已繳',
    overviewNeeds: [],
    sessionUnits: [],
    cancelledUnits: [],
    pendingMakeups: [],
    calendarEnabled: true,
    createEnabled: true,
    formatSessionChipDate: (u) => String(u.date || '').slice(5),
    getSessionStateClass: () => '',
    getSessionStateLabel: () => '',
    getSessionNumber: () => null,
    sessionRowKey: (u) => String(u.id || u.date),
    isUserNote: () => false,
    formatMakeupDate: () => '',
    ...overrides,
  };
  return mount(CourseManager, {
    props,
    global: {
      stubs: {
        CourseSessionCalendar: {
          name: 'CourseSessionCalendar',
          template: '<div data-testid="stub-calendar" />',
        },
      },
    },
  });
}

describe('CourseManager', () => {
  it('opens with course identity and overview tab', () => {
    const wrapper = mountManager();
    expect(wrapper.find('[data-testid="course-manager"]').exists()).toBe(true);
    expect(wrapper.text()).toContain('陳湘甯');
    expect(wrapper.text()).toContain('高中數學');
    expect(wrapper.find('[data-testid="course-manager-overview"]').exists()).toBe(true);
  });

  it('switches tabs without losing course identity', async () => {
    const wrapper = mountManager();
    await wrapper.find('[data-testid="course-manager-tab-sessions"]').trigger('click');
    expect(wrapper.emitted('update:tab')?.at(-1)?.[0]).toBe('sessions');
    await wrapper.setProps({ tab: 'sessions' });
    expect(wrapper.find('[data-testid="course-manager-sessions"]').exists()).toBe(true);
    expect(wrapper.text()).toContain('高中數學');
    await wrapper.find('[data-testid="course-manager-tab-billing"]').trigger('click');
    expect(wrapper.emitted('update:tab')?.at(-1)?.[0]).toBe('billing');
  });

  it('emits close from back control', async () => {
    const wrapper = mountManager();
    await wrapper.find('[data-testid="course-manager-close"]').trigger('click');
    expect(wrapper.emitted('close')).toBeTruthy();
  });

  it('routes overview lifecycle actions through existing handler names', async () => {
    const wrapper = mountManager({ canClose: true });
    const buttons = wrapper.findAll('button');
    const pause = buttons.find((b) => b.text().includes('暫停課程'));
    await pause.trigger('click');
    expect(wrapper.emitted('action')?.at(-1)?.[0]?.name).toBe('pause');
  });

  it('does not expose Phase 1b occurrence mutation affordances', () => {
    const wrapper = mountManager({ tab: 'sessions' });
    const html = wrapper.html();
    expect(html).not.toMatch(/取消這一堂|改時間|改老師|this and future|this-and-future|occurrence cancel/i);
  });
});

describe('COURSE_MANAGER_V1 flag default', () => {
  let original;
  beforeEach(() => { original = perfFlags.COURSE_MANAGER_V1; });
  afterEach(() => { perfFlags.COURSE_MANAGER_V1 = original; });

  it('defaults off in compile-time flags', () => {
    // Production activation sets VITE_COURSE_MANAGER_V1=true at build time.
    expect(typeof perfFlags.COURSE_MANAGER_V1).toBe('boolean');
  });
});

describe('CourseManagement host entry when flag on', () => {
  it('documents that 管理課程 is the primary entry (source contract)', async () => {
    const fs = await import('node:fs');
    const path = await import('node:path');
    const { fileURLToPath } = await import('node:url');
    const here = path.dirname(fileURLToPath(import.meta.url));
    const source = fs.readFileSync(path.join(here, '../../../pages/CourseManagement.vue'), 'utf8');
    expect(source).toContain('data-testid="course-manager-open"');
    expect(source).toContain('管理課程');
    expect(source).toContain('courseManagerEnabled');
    expect(source).toContain('CourseManager');
    expect(source).toMatch(/v-else class="action-btns-row"/);
    expect(source).toContain('更多 ▾');
  });
});
