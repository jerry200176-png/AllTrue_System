import { describe, it, expect } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { defineComponent, h, ref, KeepAlive } from 'vue';
import AtDialog from '../AtDialog.vue';
import ConfirmDialogHost from '../../course-management/ConfirmDialogHost.vue';
import { askConfirm, settleConfirm } from '../../../composables/useConfirmDialog';

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

  it('only the visible page\'s confirm host opens the shared confirm', async () => {
    const PageA = defineComponent({ name: 'PageA', setup: () => () => h(ConfirmDialogHost) });
    const PageB = defineComponent({ name: 'PageB', setup: () => () => h(ConfirmDialogHost) });
    const Shell = defineComponent({
      props: { page: String },
      setup: (p) => () => h('div', [
        h(KeepAlive, null, { default: () => (p.page === 'a' ? h(PageA) : null) }),
        h(KeepAlive, null, { default: () => (p.page === 'b' ? h(PageB) : null) }),
      ]),
    });
    const w = mount(Shell, { props: { page: 'a' }, attachTo: document.body });
    await w.setProps({ page: 'b' });
    await flushPromises();
    const answer = askConfirm({ title: 'T', message: 'M' });
    await flushPromises();
    expect(document.body.querySelectorAll('[data-testid="confirm-dialog-confirm"]')).toHaveLength(1);
    settleConfirm(false);
    await answer;
    w.unmount();
  });
});
