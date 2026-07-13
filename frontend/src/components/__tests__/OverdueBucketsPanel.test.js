import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import OverdueBucketsPanel from '../OverdueBucketsPanel.vue';

const mockToken = 'test-token';
beforeEach(() => {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: mockToken }));
  global.fetch = vi.fn();
});

describe('OverdueBucketsPanel.vue', () => {
  it('renders loading state initially', () => {
    global.fetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({ buckets: [] }),
    });

    const wrapper = mount(OverdueBucketsPanel);
    expect(wrapper.find('.obp-loading').exists()).toBe(true);
  });

  it('renders 4 bucket cards after data loads', async () => {
    global.fetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({
        as_of: '2026-07-13',
        buckets: [
          { key: 'upcoming', label: '即將到期(≤3天)', count: 12, total_outstanding: 96000 },
          { key: 'mild', label: '逾期1-7天', count: 5, total_outstanding: 40000 },
          { key: 'moderate', label: '逾期8-30天', count: 3, total_outstanding: 27000 },
          { key: 'severe', label: '逾期>30天', count: 1, total_outstanding: 12000 },
        ],
      }),
    });

    const wrapper = mount(OverdueBucketsPanel);
    await new Promise(r => setTimeout(r, 50));
    await nextTick();

    const cards = wrapper.findAll('.obp-bucket-card');
    expect(cards).toHaveLength(4);
    expect(wrapper.text()).toContain('12');
    expect(wrapper.text()).toContain('NT$ 96,000');
    expect(wrapper.text()).toContain('5');
    expect(wrapper.text()).toContain('NT$ 40,000');
    expect(wrapper.text()).toContain('3');
    expect(wrapper.text()).toContain('NT$ 27,000');
    expect(wrapper.text()).toContain('1');
    expect(wrapper.text()).toContain('NT$ 12,000');
  });

  it('displays color-coded cards with correct classes', async () => {
    global.fetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({
        buckets: [
          { key: 'upcoming', label: '即將到期', count: 1, total_outstanding: 1000 },
          { key: 'mild', label: '逾期1-7天', count: 1, total_outstanding: 1000 },
          { key: 'moderate', label: '逾期8-30天', count: 1, total_outstanding: 1000 },
          { key: 'severe', label: '逾期>30天', count: 1, total_outstanding: 1000 },
        ],
      }),
    });

    const wrapper = mount(OverdueBucketsPanel);
    await new Promise(r => setTimeout(r, 50));
    await nextTick();

    expect(wrapper.find('.obp-upcoming').exists()).toBe(true);
    expect(wrapper.find('.obp-mild').exists()).toBe(true);
    expect(wrapper.find('.obp-moderate').exists()).toBe(true);
    expect(wrapper.find('.obp-severe').exists()).toBe(true);
  });

  it('drills down to student list on card click', async () => {
    global.fetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({
        buckets: [{ key: 'mild', label: '逾期1-7天', count: 2, total_outstanding: 8000 }],
      }),
    });

    const wrapper = mount(OverdueBucketsPanel);
    await new Promise(r => setTimeout(r, 50));
    await nextTick();

    // Mock drill-down API
    global.fetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({
        data: [
          {
            id: 1, student_name: '學生A', subject: '英文', class_name: '英一班',
            due_date: '2026-07-10', overdue_days: 3, total_amount: 5000, paid_amount: 0,
            status: 'unpaid', student_class_id: 101,
          },
        ],
      }),
    });

    await wrapper.find('.obp-mild').trigger('click');
    await new Promise(r => setTimeout(r, 50));
    await nextTick();

    expect(wrapper.find('.obp-drilldown-header').exists()).toBe(true);
    expect(wrapper.text()).toContain('學生A');
    expect(wrapper.text()).toContain('逾期 3 天');
  });

  it('shows overdue days coloring in drill-down table', async () => {
    const wrapper = mount(OverdueBucketsPanel);
    wrapper.vm.activeBucket = 'mild';
    wrapper.vm.drillRows = [
      { id: 1, student_name: 'A', subject: 'S', class_name: 'C', due_date: '2026-07-10', overdue_days: 3, total_amount: 5000, paid_amount: 0, status: 'unpaid', student_class_id: 1 },
      { id: 2, student_name: 'B', subject: 'S', class_name: 'C', due_date: '2026-06-10', overdue_days: 33, total_amount: 5000, paid_amount: 0, status: 'unpaid', student_class_id: 2 },
    ];
    await nextTick();

    const rows = wrapper.findAll('.obp-table tbody tr');
    expect(rows).toHaveLength(2);
    expect(rows[0].classes()).toContain('odr-mild');
    expect(rows[1].classes()).toContain('odr-severe');
  });

  it('renders empty state when no buckets', async () => {
    global.fetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({ buckets: [] }),
    });

    const wrapper = mount(OverdueBucketsPanel);
    await new Promise(r => setTimeout(r, 50));
    await nextTick();

    expect(wrapper.find('.obp-empty').exists()).toBe(true);
    expect(wrapper.text()).toContain('無逾期或即將到期帳單');
  });

  it('displays error state on fetch failure', async () => {
    global.fetch.mockResolvedValueOnce({
      ok: false,
      status: 500,
      json: () => Promise.resolve({ message: '伺服器錯誤' }),
    });

    const wrapper = mount(OverdueBucketsPanel);
    await new Promise(r => setTimeout(r, 50));
    await nextTick();

    expect(wrapper.find('.obp-error').exists()).toBe(true);
    expect(wrapper.text()).toContain('伺服器錯誤');
  });

  it('formats currency with thousand separators', async () => {
    global.fetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({
        buckets: [{ key: 'upcoming', label: '即將到期', count: 1, total_outstanding: 1234567 }],
      }),
    });

    const wrapper = mount(OverdueBucketsPanel);
    await new Promise(r => setTimeout(r, 50));
    await nextTick();

    expect(wrapper.text()).toContain('NT$ 1,234,567');
  });
});
