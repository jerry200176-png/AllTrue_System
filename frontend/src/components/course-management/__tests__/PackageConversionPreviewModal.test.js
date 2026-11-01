import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import PackageConversionPreviewModal from '../PackageConversionPreviewModal.vue';

describe('PackageConversionPreviewModal', () => {
  it('renders the preview and emits convert when conversion is allowed', async () => {
    const wrapper = mount(PackageConversionPreviewModal, {
      props: {
        show: true,
        course: { subject_name: '數學', student_name: '小明' },
        preview: { can_convert: true, next_step: '下一步', student_class: { remaining_sessions: 3 } },
      },
    });
    expect(wrapper.find('[data-testid="package-conversion-preview"]').exists()).toBe(true);
    expect(wrapper.text()).toContain('3 堂剩餘');
    await wrapper.find('button.primary').trigger('click');
    expect(wrapper.emitted('convert')).toHaveLength(1);
    await wrapper.find('button.ghost').trigger('click');
    expect(wrapper.emitted('close')).toHaveLength(1);
  });

  it('emits new-package when blocked', async () => {
    const wrapper = mount(PackageConversionPreviewModal, {
      props: { show: true, preview: { can_convert: false, blocking_reasons: [{ code: 'x', message: '有點名' }] } },
    });
    expect(wrapper.text()).toContain('有點名');
    await wrapper.find('button.primary').trigger('click');
    expect(wrapper.emitted('new-package')).toHaveLength(1);
  });
});
