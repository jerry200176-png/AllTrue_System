import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import WeekTeacherChips from '../WeekTeacherChips.vue';

const teachers = [
  { id: 1, username: '王老師', color: '#1E88E5' },
  { id: 2, username: '李老師', color: '#43A047' },
];

describe('WeekTeacherChips', () => {
  // 正常：渲染 chips、active 比對、active 套色、emit toggle 帶 id
  it('renders chips, marks active by selectedIds, applies color, emits toggle', async () => {
    const wrapper = mount(WeekTeacherChips, {
      props: { teachers, selectedIds: ['1'] },
    });
    const chips = wrapper.findAll('.week-teacher-chip');
    expect(chips).toHaveLength(2);
    expect(chips[0].text()).toBe('王老師');
    expect(chips[0].classes()).toContain('active');
    expect(chips[1].classes()).not.toContain('active');
    expect(chips[0].attributes('aria-pressed')).toBe('true');
    expect(chips[0].attributes('style')).toContain('background: rgb(30, 136, 229)');
    await chips[1].trigger('click');
    expect(wrapper.emitted('toggle')[0]).toEqual([2]);
  });

  // 邊界：clear 按鈕只在有選取時出現，並 emit clear
  it('shows clear only when something selected and emits clear', async () => {
    const none = mount(WeekTeacherChips, { props: { teachers, selectedIds: [] } });
    expect(none.find('.week-teacher-chip-clear').exists()).toBe(false);
    const some = mount(WeekTeacherChips, { props: { teachers, selectedIds: ['2'] } });
    const clear = some.find('.week-teacher-chip-clear');
    expect(clear.exists()).toBe(true);
    await clear.trigger('click');
    expect(some.emitted('clear')).toBeTruthy();
  });

  // 空值：無 teachers 不崩潰、不顯示 clear
  it('renders safely with empty teachers', () => {
    const wrapper = mount(WeekTeacherChips);
    expect(wrapper.findAll('.week-teacher-chip')).toHaveLength(0);
    expect(wrapper.find('.week-teacher-chip-clear').exists()).toBe(false);
    expect(wrapper.find('.week-teacher-chips-label').text()).toBe('老師');
  });

  // 邊界：selectedIds 為數字字串化比對（id 為 number 仍正確 active）
  it('matches numeric id against string selectedIds', () => {
    const wrapper = mount(WeekTeacherChips, { props: { teachers, selectedIds: ['2'] } });
    const chips = wrapper.findAll('.week-teacher-chip');
    expect(chips[1].classes()).toContain('active');
  });
});
