import { describe, it, expect } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { defineComponent, h, ref, KeepAlive } from 'vue';
import AtDialog from '../AtDialog.vue';

describe('AtDialog inside a kept-alive page', () => {
  it('closes and releases the scroll lock when the page is deactivated', async () => {
    const open = ref(true);
    const Page = defineComponent({
      setup: () => () => h(AtDialog, { open: open.value, title: 't', onClose: () => { open.value = false; } }, { default: () => 'x' }),
    });
    const Shell = defineComponent({
      props: { show: Boolean },
      setup: (p) => () => h('div', [h(KeepAlive, null, { default: () => (p.show ? h(Page) : null) })]),
    });
    const w = mount(Shell, { props: { show: true }, attachTo: document.body });
    await flushPromises();
    expect(document.body.style.overflow).toBe('hidden');
    await w.setProps({ show: false });
    await flushPromises();
    expect(open.value).toBe(false);
    expect(document.body.style.overflow).toBe('');
    w.unmount();
  });
});
