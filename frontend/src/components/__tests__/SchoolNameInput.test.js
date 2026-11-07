import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import SchoolNameInput from '../SchoolNameInput.vue';

afterEach(() => vi.unstubAllGlobals());

describe('SchoolNameInput campus names (#517)', () => {
  it('offers this campus spellings first and picks one', async () => {
    const f = vi.fn(async (url) => ({
      ok: true,
      json: async () => (String(url).includes('school-names')
        ? { data: [{ name: '甲測國中', count: 4 }, { name: '乙測國中', count: 1 }] }
        : { data: [{ id: 'x', canonical_name: '甲測國中' }, { id: 'y', canonical_name: '甲測女中' }] }),
    }));
    vi.stubGlobal('fetch', f);
    const w = mount(SchoolNameInput, { props: { authToken: 't', campusId: 1, modelValue: '' } });
    await flushPromises();
    await w.find('input').trigger('focus');
    await new Promise((r) => setTimeout(r, 250));
    await flushPromises();
    expect(w.findAll('li[role="option"]').map((l) => l.text())).toEqual([
      expect.stringContaining('甲測國中'), expect.stringContaining('乙測國中'),
    ]);
    await w.find('input').setValue('甲測');
    await new Promise((r) => setTimeout(r, 250));
    await flushPromises();
    const opts = w.findAll('li[role="option"]');
    expect(opts).toHaveLength(2);
    expect(opts[0].text()).toContain('本校已有 4 人');
    expect(opts[1].text()).toContain('甲測女中');
    await opts[0].trigger('mousedown');
    expect(w.emitted('update:modelValue').at(-1)).toEqual(['甲測國中']);
  });
});
