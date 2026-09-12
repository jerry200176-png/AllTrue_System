import { describe, expect, it } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import CourseEditForm from '../CourseEditForm.vue';

describe('existing-course tutoring copy', () => {
  it('clarifies the reference rate without changing the model value', async () => {
    const modelValue = { class_type: 'tutoring', rate_per_30min: 1500, rate_unit: 'session', sessions_purchased: 8 };
    const wrapper = shallowMount(CourseEditForm, { props: { modelValue } });
    expect(wrapper.text()).toContain('不是向學生收費');
    expect(wrapper.text()).toContain('課務與核薪參考單價（元）');
    expect(wrapper.text()).toContain('課程期間計算方式');
    expect(wrapper.find('input[placeholder="1500"]').element.value).toBe('1500');
    await wrapper.find('input[placeholder="1500"]').setValue('1600');
    expect(wrapper.emitted('update:modelValue').at(-1)[0]).toMatchObject({
      class_type: 'tutoring', rate_per_30min: 1600, sessions_purchased: 8,
    });
    expect(modelValue.rate_per_30min).toBe(1500);
    wrapper.unmount();
  });

  it('preserves paid-course terminology and rate', () => {
    const wrapper = shallowMount(CourseEditForm, { props: {
      modelValue: { class_type: 'one_on_one', rate_per_30min: 1500, rate_unit: 'hour' },
    } });
    expect(wrapper.text()).not.toContain('不是向學生收費');
    expect(wrapper.text()).toContain('每小時費用（元）');
    expect(wrapper.text()).toContain('繳費方式');
    expect(wrapper.find('input[placeholder="1500"]').element.value).toBe('1500');
    wrapper.unmount();
  });
});
