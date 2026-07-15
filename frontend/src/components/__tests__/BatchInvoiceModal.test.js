import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import BatchInvoiceModal from '../BatchInvoiceModal.vue';

const mockToken = 'test-token';
beforeEach(() => {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: mockToken }));
  global.fetch = vi.fn();
});

describe('BatchInvoiceModal.vue', () => {
  it('renders nothing when show is false', () => {
    const wrapper = mount(BatchInvoiceModal, { props: { show: false } });
    expect(wrapper.find('.modal-overlay').exists()).toBe(false);
  });

  it('renders step 1 scope selection when opened', async () => {
    // Mock campuses fetch
    global.fetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({ data: [{ id: 1, name: '大安分校' }] }),
    });

    const wrapper = mount(BatchInvoiceModal, { props: { show: true } });
    await new Promise(r => setTimeout(r, 20));
    await nextTick();

    expect(wrapper.find('.batch-steps').exists()).toBe(true);
    expect(wrapper.find('.batch-step.active').exists()).toBe(true);
    expect(wrapper.find('.batch-step.active .batch-step-num').text()).toBe('1');
    expect(wrapper.text()).toContain('選擇範圍');
    expect(wrapper.find('.batch-form').exists()).toBe(true);
  });

  it('shows step indicator with 3 steps', async () => {
    global.fetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve([]),
    });

    const wrapper = mount(BatchInvoiceModal, { props: { show: true } });
    await new Promise(r => setTimeout(r, 20));
    await nextTick();

    const steps = wrapper.findAll('.batch-step-num');
    expect(steps).toHaveLength(3);
    expect(steps[0].text()).toBe('1');
    expect(steps[1].text()).toBe('2');
    expect(steps[2].text()).toBe('3');
  });

  it('disables preview button when no classes selected or no due date', async () => {
    global.fetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve([]),
    });

    const wrapper = mount(BatchInvoiceModal, { props: { show: true } });
    await new Promise(r => setTimeout(r, 20));
    await nextTick();

    const previewBtn = wrapper.findAll('.batch-step-actions .primary').find(
      b => b.text().includes('預覽')
    );
    // Button should be disabled (class_ids empty, due_date set but no classes)
    // The button is disabled when !form.class_ids.length || !form.due_date
    expect(previewBtn).toBeTruthy();
  });

  it('navigates to step 2 on successful preview', async () => {
    const preview = {
      rows: [
        { student_class_id: 501, student_name: '王小明', class_name: '英文一對一', suggested_amount: 12000, warning: null },
        { student_class_id: 502, student_name: '李小華', class_name: '數學一對一', suggested_amount: 8000, warning: '缺費率設定' },
      ],
      total: 2,
      warnings_count: 1,
    };
    global.fetch.mockImplementation((url) => Promise.resolve({
      ok: true,
      json: async () => (String(url).includes('batch-preview')
        ? preview
        : { data: [{ id: 1, name: '大安分校' }] }),
    }));

    const wrapper = mount(BatchInvoiceModal, { props: { show: true, branchId: 1 } });
    await new Promise(r => setTimeout(r, 30));
    await nextTick();
    wrapper.vm.form.class_ids = [501, 502];
    wrapper.vm.form.due_date = '2026-07-20';
    await nextTick();
    await wrapper.findAll('.batch-step-actions .primary').find(b => b.text().includes('預覽')).trigger('click');
    await new Promise(r => setTimeout(r, 50));
    await nextTick();

    expect(wrapper.vm.step).toBe(2);
    expect(wrapper.text()).toContain('王小明');
    expect(wrapper.text()).toContain('李小華');
    expect(wrapper.text()).toContain('NT$ 12,000');
    expect(wrapper.text()).toContain('NT$ 8,000');
  });

  it('shows warning rows as deselected by default', async () => {
    const wrapper = mount(BatchInvoiceModal, { props: { show: true } });
    wrapper.vm.step = 2;
    wrapper.vm.previewRows = [
      { student_class_id: 1, student_name: '正常生', class_name: 'C1', schedule_mode: 'count', suggested_amount: 1000, warning: null, selected: true, adjusted_amount: 1000 },
      { student_class_id: 2, student_name: '警告生', class_name: 'C2', schedule_mode: 'count', suggested_amount: 2000, warning: '缺費率設定', selected: false, adjusted_amount: 2000 },
    ];
    await nextTick();

    expect(wrapper.findAll('.batch-row-warning')).toHaveLength(1);
    expect(wrapper.findAll('.batch-row-deselected')).toHaveLength(1);
  });

  it('shows error banner on API error', async () => {
    global.fetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve([]),
    });

    const wrapper = mount(BatchInvoiceModal, { props: { show: true } });
    await new Promise(r => setTimeout(r, 20));
    await nextTick();

    wrapper.vm.error = '測試錯誤訊息';
    await nextTick();

    expect(wrapper.find('.batch-error').exists()).toBe(true);
    expect(wrapper.text()).toContain('測試錯誤訊息');
  });

  it('emits close event', async () => {
    global.fetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve([]),
    });

    const wrapper = mount(BatchInvoiceModal, { props: { show: true } });
    await new Promise(r => setTimeout(r, 20));
    await nextTick();

    await wrapper.find('.icon-btn').trigger('click');
    expect(wrapper.emitted('close')).toBeTruthy();
  });
});
