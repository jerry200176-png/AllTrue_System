import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import LessonPickerSelect from '../LessonPickerSelect.vue';

const options = [1, 2, 3].map((n) => ({ key: `k${n}`, label: `10/0${n} (三) 18:30` }));

describe('LessonPickerSelect', () => {
  it('asks which lesson, per mode, with the chosen lesson selected', () => {
    const w = mount(LessonPickerSelect, { props: { picker: { mode: 'reschedule', options, key: 'k2' } } });
    expect(w.text()).toContain('要調哪一堂？');
    expect(w.get('select').element.value).toBe('k2');
    expect(w.findAll('option')).toHaveLength(3);
    expect(mount(LessonPickerSelect, { props: { picker: { mode: 'substitute', options, key: 'k1' } } }).text()).toContain('要代哪一堂？');
  });

  it('shows the key it is given (derived by the page) and locks while a pick is opening', () => {
    const w = mount(LessonPickerSelect, { props: { picker: { mode: 'reschedule', options, key: 'k3', busy: true } } });
    expect(w.get('select').element.value).toBe('k3');
    expect(w.get('select').attributes('disabled')).toBeDefined();
  });

  it('emits the chosen key; renders nothing for one lesson or no picker', async () => {
    const w = mount(LessonPickerSelect, { props: { picker: { mode: 'reschedule', options, key: 'k1' } } });
    await w.get('select').setValue('k3');
    expect(w.emitted('pick')).toEqual([['k3']]);
    expect(mount(LessonPickerSelect, { props: { picker: null } }).html()).toBe('<!--v-if-->');
    expect(mount(LessonPickerSelect, { props: { picker: { mode: 'reschedule', options: options.slice(0, 1), key: 'k1' } } }).find('select').exists()).toBe(false);
  });
});
