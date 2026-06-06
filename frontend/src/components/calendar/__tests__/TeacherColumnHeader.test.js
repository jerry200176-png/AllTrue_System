import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import TeacherColumnHeader from '../TeacherColumnHeader.vue';

describe('TeacherColumnHeader', () => {
  // 正常
  it('renders name, room, initial and applies color to avatar + border-top', () => {
    const wrapper = mount(TeacherColumnHeader, {
      props: { name: '王老師', room: 'A101', color: '#1E88E5' },
    });
    expect(wrapper.find('.teacher-col-name').text()).toBe('王老師');
    expect(wrapper.find('.teacher-col-room').exists()).toBe(true);
    expect(wrapper.find('.teacher-col-room').text()).toBe('A101');
    expect(wrapper.find('.teacher-col-avatar').text()).toBe('王'); // 取首字
    expect(wrapper.find('.teacher-col-avatar').attributes('style')).toContain('background: rgb(30, 136, 229)');
    expect(wrapper.attributes('style')).toContain('border-top-color: rgb(30, 136, 229)');
  });

  // 邊界：compact prop 驅動（取代原祖先選擇器）
  it('toggles tch-compact class via compact prop', () => {
    const compact = mount(TeacherColumnHeader, { props: { name: '李', compact: true } });
    expect(compact.classes()).toContain('tch-compact');
    const normal = mount(TeacherColumnHeader, { props: { name: '李' } });
    expect(normal.classes()).not.toContain('tch-compact');
  });

  // 空值：無 name / 無 room
  it('handles empty name and hides room when blank', () => {
    const wrapper = mount(TeacherColumnHeader, { props: { name: '', room: '' } });
    expect(wrapper.find('.teacher-col-avatar').text()).toBe(''); // 不丟 charAt of undefined
    expect(wrapper.find('.teacher-col-name').text()).toBe('');
    expect(wrapper.find('.teacher-col-room').exists()).toBe(false); // v-if 不渲染
  });

  // 邊界：未給 color → fallback 灰，且 room 省略時仍渲染表頭
  it('falls back to default grey color when color not provided', () => {
    const wrapper = mount(TeacherColumnHeader, { props: { name: '張老師' } });
    expect(wrapper.find('.teacher-col-avatar').attributes('style')).toContain('background: rgb(144, 164, 174)'); // #90A4AE
    expect(wrapper.find('.teacher-col-room').exists()).toBe(false);
  });
});
