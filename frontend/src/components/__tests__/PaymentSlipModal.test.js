import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import PaymentSlipModal from '../PaymentSlipModal.vue';
import { domToBlob } from 'modern-screenshot';

vi.mock('modern-screenshot', () => ({ domToBlob: vi.fn() }));

const ok = (body) => ({ ok: true, json: async () => body });
const INVOICE = {
  invoice_id: 1623, student_name: '王小明', campus_name: '木柵分校', status: 'partial',
  remaining: 3600, total_amount: 6600, paid_amount: 3000, due_date: '2026-08-28', issue_date: '2026-08-15',
  items: [{ description: '數學月結費用', amount: 6600, period_start: '2026-08-15', period_end: '2026-09-13' }],
  sessions: [{ date: '2026-08-04', start_time: '18:00', end_time: '20:00', status: 'attended' }],
};
const TUITION = {
  student_class_id: 9, student_name: '王小明', subject: '英文', schedule_mode: 'count',
  remaining_sessions: 3, estimated_amount: 4500, sessions: [{ date: '2026-09-02', status: 'scheduled' }],
};

describe('PaymentSlipModal', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
    domToBlob.mockReset();
    domToBlob.mockResolvedValue(new Blob(['png'], { type: 'image/png' }));
    global.fetch = vi.fn();
    Object.defineProperty(document, 'fonts', { configurable: true, value: { ready: Promise.resolve() } });
  });

  it('renders an invoice slip and downloads the captured PNG', async () => {
    global.fetch.mockResolvedValueOnce(ok(INVOICE));
    vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:slip');
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {});
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});

    const wrapper = mount(PaymentSlipModal, { props: { show: true, invoiceId: 1623 } });
    await flushPromises();

    expect(global.fetch.mock.calls[0][0]).toBe('/api/v1/invoices/1623/slip-data');
    expect(wrapper.text()).toContain('尚欠金額');
    expect(wrapper.text()).toContain('NT$ 3,600');
    expect(wrapper.text()).toContain('2026/08/04（二）');

    await wrapper.find('button.primary').trigger('click');
    await flushPromises();
    expect(domToBlob).toHaveBeenLastCalledWith(wrapper.find('.slip').element, expect.objectContaining({ font: false }));
    expect(click).toHaveBeenCalled();
  });

  it('renders a count-mode tuition notice as 課程明細', async () => {
    global.fetch.mockResolvedValueOnce(ok(TUITION));
    const wrapper = mount(PaymentSlipModal, { props: { show: true, studentClassId: 9 } });
    await flushPromises();

    expect(global.fetch.mock.calls[0][0]).toBe('/api/v1/alerts/tuition-slip/9');
    expect(wrapper.text()).toContain('預估金額（尚無帳單）');
    expect(wrapper.text()).toContain('課程明細');
    expect(wrapper.text()).toContain('剩餘 3 堂');
  });
});
