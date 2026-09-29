import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';

vi.mock('../../supabase', () => ({
  supabase: { auth: { getSession: async () => ({ data: { session: { access_token: 'synthetic-token' } } }) } },
}));
import StudentsList from '../../pages/StudentsList.vue';

afterEach(() => vi.unstubAllGlobals());

const buttonLabel = (button) => {
  const element = button.element.cloneNode(true);
  element.querySelectorAll('[aria-hidden="true"]').forEach((icon) => icon.remove());
  return element.textContent.trim();
};

const course = {
  id: 7001, subject: 'Math', subject_name: '數學', class_type: 'tutoring',
  payment_type: 'session', payment_status: 'unpaid', status: 'active',
  sessions_purchased: 8, remaining_sessions: 6, teacher_name: '合成老師',
  branch_name: '合成分校', room_name: '合成教室', days_of_week: [1],
  start_time: '16:00', duration_hours: 2, tutoring_billing_anomaly: false,
};

async function withCourse(overrides, assertions) {
  const fetchMock = vi.fn(async (input) => {
    const url = String(input);
    const body = url.includes('/student-classes') ? { data: [{ ...course, ...overrides }] }
      : url.includes('/students?') ? { data: [{ id: 2001, name: '合成學生', status: 'active' }], total: 1 }
        : url.includes('/class-sessions') ? { byClass: { 7001: [] } } : { data: [] };
    return { ok: true, json: async () => body };
  });
  vi.stubGlobal('fetch', fetchMock);
  const wrapper = shallowMount(StudentsList, { props: { branchId: 1 } });
  try {
    await flushPromises();
    await wrapper.find('.student-row').trigger('click');
    await flushPromises();
    expect(wrapper.findAll('.student-course-card')).toHaveLength(1);
    await assertions(wrapper);
    expect(fetchMock.mock.calls.every(([, options]) => !options?.method || options.method === 'GET')).toBe(true);
  } finally {
    wrapper.unmount();
  }
}

describe('In-App #351: tutoring next steps preserve the no-charge and anomaly contracts', () => {
  it.each(['unpaid', 'overdue', 'pending'])('does not invent a payment task for normal tutoring with %s status', async (payment_status) => {
    await withCourse({ payment_status }, (wrapper) => {
      const card = wrapper.find('.student-course-card');
      expect(card.text()).toContain('無須繳費');
      expect(card.find('.student-course-card__next-step').text()).toContain('課程資料已齊全');
      expect(card.find('.student-course-card__next-step').text()).not.toContain('付款');
      expect(wrapper.find('.student-course-picker__status').text()).toBe('進行中');
      expect(wrapper.find('.student-course-overview__metric--attention').exists()).toBe(false);
    });
  });

  it.each(['unpaid', 'paid'])('keeps explicit accounting anomalies actionable with %s status', async (payment_status) => {
    await withCourse({ payment_status, tutoring_billing_anomaly: true }, async (wrapper) => {
      expect(wrapper.find('.student-course-card [role="alert"]').text()).toContain('帳務資料需修正');
      expect(wrapper.find('.student-course-picker__status').text()).toBe('帳務資料需修正');
      expect(wrapper.find('.student-course-overview__metric--attention strong').text()).toBe('1');
      expect(wrapper.find('.student-course-card__next-step').text()).toContain('輔導課無須繳費');
      expect(wrapper.find('.student-course-card__next-step').text()).not.toContain('先確認付款狀態');
      const action = wrapper.find('.student-course-card__primary');
      expect(buttonLabel(action)).toBe('查看帳務資料');
      await action.trigger('click');
      expect(wrapper.emitted('navigate')).toHaveLength(1);
    });
  });

  it('preserves unpaid ordinary-course tasks', async () => {
    await withCourse({ class_type: 'one_on_one' }, (wrapper) => {
      expect(wrapper.find('.student-course-card__next-step').text()).toContain('先確認付款狀態');
      expect(wrapper.find('.student-course-picker__status').text()).toBe('付款待確認');
      expect(wrapper.find('.student-course-overview__metric--attention strong').text()).toBe('1');
    });
  });

  it('still prioritizes tutoring renewal when remaining sessions are low', async () => {
    await withCourse({ remaining_sessions: 1 }, (wrapper) => {
      expect(buttonLabel(wrapper.find('.student-course-card__primary'))).toBe('延續輔導課');
      expect(wrapper.find('.student-course-picker__status').text()).toBe('需要續報');
      expect(wrapper.find('.student-course-overview__metric--attention strong').text()).toBe('1');
    });
  });
});
