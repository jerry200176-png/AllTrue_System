// In-app #425: the slip can open over the student ledger, so it behaves like ReceiptModal as a nested dialog:
// it takes focus, Escape closes only the slip, and focus goes back to the opener.
import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import PaymentSlipModal from '../PaymentSlipModal.vue';

afterEach(() => { vi.unstubAllGlobals(); document.body.innerHTML = ''; });

describe('PaymentSlipModal nested-dialog focus (in-app #425)', () => {
  it('focuses its panel, closes on Escape without bubbling, and returns focus to the opener', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 422, json: async () => ({ message: '金額待確認，請先確認應收金額' }) })));
    const opener = document.createElement('button');
    document.body.appendChild(opener);
    opener.focus();
    const outer = vi.fn();
    document.body.addEventListener('keydown', outer);
    const w = mount(PaymentSlipModal, { attachTo: document.body, props: { show: false, invoiceId: 3 } });
    await w.setProps({ show: true });
    await flushPromises();
    await nextTick();
    const panel = w.get('[data-testid="slip-modal-panel"]');
    expect(panel.attributes('role')).toBe('dialog');
    expect(document.activeElement).toBe(panel.element);
    await panel.trigger('keydown', { key: 'Escape' });
    expect(w.emitted('close')).toHaveLength(1);
    expect(outer).not.toHaveBeenCalled();
    await w.setProps({ show: false });
    expect(document.activeElement).toBe(opener);
    document.body.removeEventListener('keydown', outer);
    w.unmount();
  });
});
