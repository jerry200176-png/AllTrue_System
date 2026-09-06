import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import CoursePaymentDateField from '../CoursePaymentDateField.vue';
import CourseEditForm from '../CourseEditForm.vue';
import { nextTick } from 'vue';

describe('payment-date draft recovery (#252)', () => {
  it('updates the real course draft without changing its original payment date or price', async () => {
    const wrapper = mount(CourseEditForm, { props: {
      modelValue: { paid_at: '2026-09-05', original_paid_at: '2026-09-05', rate_per_30min: 2750, memo: 'keep' },
    } });
    await nextTick();
    const clear = wrapper.findAll('button').find((button) => button.text().includes('改為未繳費'));
    expect(clear).toBeDefined();
    await clear.trigger('click');
    expect(wrapper.emitted('update:modelValue').at(-1)[0]).toMatchObject({
      paid_at: '', original_paid_at: '2026-09-05', rate_per_30min: 2750, memo: 'keep',
    });
    wrapper.unmount();
  });

  it('clears only the draft date without submitting a form', async () => {
    const wrapper = mount(CoursePaymentDateField, { props: { modelValue: '2026-09-05' } });
    const button = wrapper.get('button');
    expect(button.attributes('type')).toBe('button');
    expect(button.text()).toContain('改為未繳費');
    await button.trigger('click');
    expect(wrapper.emitted('update:modelValue')).toEqual([['']]);
    expect(wrapper.emitted('open-billing')).toBeUndefined();
    await wrapper.setProps({ modelValue: '' });
    expect(wrapper.text()).toContain('儲存後');
    expect(wrapper.find('button').exists()).toBe(false);
    await wrapper.get('input').setValue('2026-09-06');
    expect(wrapper.emitted('update:modelValue').at(-1)).toEqual(['2026-09-06']);
  });

  it('shows ledger recovery instead of a misleading success hint when locked', async () => {
    const wrapper = mount(CoursePaymentDateField, {
      props: { modelValue: '2026-09-05', locked: true },
    });
    expect(wrapper.get('input').element.disabled).toBe(true);
    expect(wrapper.text()).toContain('已有收款紀錄');
    expect(wrapper.text()).not.toContain('儲存後將標示為已繳費');
    await wrapper.get('button').trigger('click');
    expect(wrapper.emitted('open-billing')).toHaveLength(1);
    expect(wrapper.emitted('update:modelValue')).toBeUndefined();
  });

  it('fails closed while editability is unknown', () => {
    const wrapper = mount(CoursePaymentDateField, {
      props: { modelValue: '2026-09-05', unavailable: true },
    });
    expect(wrapper.get('input').element.disabled).toBe(true);
    expect(wrapper.find('button').exists()).toBe(false);
    expect(wrapper.text()).toContain('確認課程狀態');
  });
});
