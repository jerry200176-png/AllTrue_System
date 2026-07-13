import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import ReceiptModal from '../ReceiptModal.vue';

// Mock localStorage
const mockToken = 'test-token';
beforeEach(() => {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: mockToken }));
  global.fetch = vi.fn();
});

describe('ReceiptModal.vue', () => {
  it('renders nothing when show is false', () => {
    const wrapper = mount(ReceiptModal, { props: { show: false } });
    expect(wrapper.find('.modal-overlay').exists()).toBe(false);
  });

  it('renders loading state when shown with receiptId', async () => {
    global.fetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({
        id: 1,
        receipt_number: '20260713-000001',
        status: 'active',
        issued_at: '2026-07-13T10:00:00+08:00',
        content_snapshot: {
          school_name: '台北全真一對一補習班｜大安分校',
          license_number: '北市教終字第XXXXX號',
          address: '台北市大安區信義路三段100號',
          student_name: '王小明',
          study_period: { start: '2026/07/01', end: '2026/12/31' },
          items: [{ description: '英文科 7-12月', amount: 12000 }],
          total_amount: 12000,
          refund_policy: '依《短期補習班設立及管理準則》第24條辦理',
          paid_at: '2026/07/13',
          method: 'cash',
        },
      }),
    });

    const wrapper = mount(ReceiptModal, {
      props: { show: true, receiptId: 1 },
    });
    await nextTick();

    expect(wrapper.find('.receipt-loading').exists()).toBe(true);
  });

  it('displays receipt content after loading', async () => {
    global.fetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({
        id: 1,
        receipt_number: '20260713-000001',
        status: 'active',
        issued_at: '2026-07-13T10:00:00+08:00',
        voided_at: null,
        void_reason: null,
        content_snapshot: {
          school_name: '台北全真一對一補習班｜大安分校',
          license_number: '北市教終字第XXXXX號',
          address: '台北市大安區信義路三段100號',
          student_name: '王小明',
          study_period: { start: '2026/07/01', end: '2026/12/31' },
          items: [
            { description: '英文科 7-12月', amount: 12000 },
            { description: '教材費', amount: 1500 },
          ],
          total_amount: 13500,
          refund_policy: '依《短期補習班設立及管理準則》第24條辦理',
          paid_at: '2026/07/13',
          method: 'cash',
        },
      }),
    });

    const wrapper = mount(ReceiptModal, {
      props: { show: true, receiptId: 1 },
    });

    // Wait for async fetch
    await new Promise(r => setTimeout(r, 50));
    await nextTick();

    // Should show receipt content
    expect(wrapper.find('.receipt-document').exists()).toBe(true);
    expect(wrapper.find('.receipt-doc-title').text()).toBe('正式收據');
    expect(wrapper.text()).toContain('王小明');
    expect(wrapper.text()).toContain('20260713-000001');
    expect(wrapper.text()).toContain('NT$ 13,500');
    expect(wrapper.text()).toContain('英文科 7-12月');
    expect(wrapper.text()).toContain('教材費');
  });

  it('shows all 8 legally required fields from content_snapshot', async () => {
    global.fetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({
        id: 1,
        receipt_number: '20260713-000001',
        status: 'active',
        issued_at: '2026-07-13T10:00:00+08:00',
        content_snapshot: {
          school_name: '測試補習班',
          license_number: '測試證號',
          address: '測試地址',
          student_name: '測試生',
          study_period: { start: '2026/01/01', end: '2026/06/30' },
          items: [{ description: '測試課程', amount: 10000 }],
          total_amount: 10000,
          refund_policy: '測試退費規定',
          paid_at: '2026/01/01',
          method: 'transfer',
        },
      }),
    });

    const wrapper = mount(ReceiptModal, { props: { show: true, receiptId: 1 } });
    await new Promise(r => setTimeout(r, 50));
    await nextTick();

    const text = wrapper.text();
    // 8 legal fields check
    expect(text).toContain('測試補習班');       // 1. school name
    expect(text).toContain('測試地址');          // 2. address
    expect(text).toContain('測試證號');          // 3. license number
    expect(text).toContain('2026/01/01');        // 4. study period
    expect(text).toContain('測試課程');          // 5. items
    expect(text).toContain('NT$ 10,000');        // 6. amounts
    expect(text).toContain('NT$ 10,000');        // 7. total (same as amount here)
    expect(text).toContain('測試退費規定');      // 8. refund policy
  });

  it('displays voided watermark and banner when receipt is voided', async () => {
    global.fetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({
        id: 1,
        receipt_number: '20260713-000001',
        status: 'voided',
        issued_at: '2026-07-13T10:00:00+08:00',
        voided_at: '2026-07-14T09:00:00+08:00',
        void_reason: '輸入錯誤',
        content_snapshot: {
          school_name: '測試補習班',
          license_number: '證號123',
          address: '地址123',
          student_name: '學生A',
          study_period: { start: '2026/07/01', end: '2026/12/31' },
          items: [{ description: '課程', amount: 5000 }],
          total_amount: 5000,
          refund_policy: '退費規定',
          paid_at: '2026/07/13',
          method: 'cash',
        },
      }),
    });

    const wrapper = mount(ReceiptModal, { props: { show: true, receiptId: 1 } });
    await new Promise(r => setTimeout(r, 50));
    await nextTick();

    // Voided watermark
    expect(wrapper.find('.receipt-voided-watermark').exists()).toBe(true);
    expect(wrapper.find('.receipt-voided-watermark').text()).toBe('作廢');

    // Voided banner
    expect(wrapper.find('.receipt-voided-banner').exists()).toBe(true);
    expect(wrapper.text()).toContain('此收據已作廢');
    expect(wrapper.text()).toContain('輸入錯誤');

    // Void button should not show for voided receipts
    expect(wrapper.find('.receipt-btn-void').exists()).toBe(false);
  });

  it('shows void button for active receipts', async () => {
    global.fetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({
        id: 1,
        receipt_number: '20260713-000001',
        status: 'active',
        issued_at: '2026-07-13T10:00:00+08:00',
        content_snapshot: {
          school_name: '測試補習班',
          license_number: '證號',
          address: '地址',
          student_name: '學生',
          study_period: { start: '2026/07/01', end: '2026/12/31' },
          items: [{ description: '課程', amount: 5000 }],
          total_amount: 5000,
          refund_policy: '規定',
          paid_at: '2026/07/13',
          method: 'cash',
        },
      }),
    });

    const wrapper = mount(ReceiptModal, { props: { show: true, receiptId: 1 } });
    await new Promise(r => setTimeout(r, 50));
    await nextTick();

    expect(wrapper.find('.receipt-btn-void').exists()).toBe(true);
  });

  it('shows error state on fetch failure', async () => {
    global.fetch.mockResolvedValueOnce({
      ok: false,
      status: 500,
      json: () => Promise.resolve({ message: '伺服器錯誤' }),
    });

    const wrapper = mount(ReceiptModal, { props: { show: true, receiptId: 1 } });
    await new Promise(r => setTimeout(r, 50));
    await nextTick();

    expect(wrapper.find('.receipt-error').exists()).toBe(true);
    expect(wrapper.text()).toContain('伺服器錯誤');
  });

  it('emits close event on close button click', async () => {
    global.fetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({
        id: 1,
        receipt_number: '20260713-000001',
        status: 'active',
        issued_at: '2026-07-13T10:00:00+08:00',
        content_snapshot: {
          school_name: '測試', license_number: 'X', address: 'A',
          student_name: 'S', study_period: { start: '2026/01/01', end: '2026/06/30' },
          items: [{ description: 'T', amount: 1000 }], total_amount: 1000,
          refund_policy: 'R', paid_at: '2026/01/01', method: 'cash',
        },
      }),
    });

    const wrapper = mount(ReceiptModal, { props: { show: true, receiptId: 1 } });
    await new Promise(r => setTimeout(r, 50));
    await nextTick();

    await wrapper.find('.icon-btn').trigger('click');
    expect(wrapper.emitted('close')).toBeTruthy();
  });

  it('opens void confirmation dialog', async () => {
    global.fetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({
        id: 1,
        receipt_number: '20260713-000001',
        status: 'active',
        issued_at: '2026-07-13T10:00:00+08:00',
        content_snapshot: {
          school_name: '測試', license_number: 'X', address: 'A',
          student_name: 'S', study_period: { start: '2026/01/01', end: '2026/06/30' },
          items: [{ description: 'T', amount: 1000 }], total_amount: 1000,
          refund_policy: 'R', paid_at: '2026/01/01', method: 'cash',
        },
      }),
    });

    const wrapper = mount(ReceiptModal, { props: { show: true, receiptId: 1 } });
    await new Promise(r => setTimeout(r, 50));
    await nextTick();

    await wrapper.find('.receipt-btn-void').trigger('click');
    await nextTick();

    expect(wrapper.find('.tc-dialog').exists()).toBe(true);
    expect(wrapper.text()).toContain('作廢收據確認');
  });

  it('handles 422 missing_fields by showing legal setup form', async () => {
    global.fetch.mockResolvedValueOnce({
      ok: false,
      status: 404,
      json: () => Promise.resolve({}),
    });
    global.fetch.mockResolvedValueOnce({
      ok: false,
      status: 422,
      json: () => Promise.resolve({
        message: '分校法定資訊未設定',
        missing_fields: ['地址', '立案證號'],
      }),
    });

    const wrapper = mount(ReceiptModal, {
      props: { show: true, reportId: 100 },
    });
    await new Promise(r => setTimeout(r, 50));
    await nextTick();
    await new Promise(r => setTimeout(r, 50));
    await nextTick();

    expect(wrapper.find('.receipt-legal-setup').exists()).toBe(true);
    expect(wrapper.text()).toContain('缺少以下欄位');
    expect(wrapper.text()).toContain('地址');
    expect(wrapper.text()).toContain('立案證號');
  });
});
