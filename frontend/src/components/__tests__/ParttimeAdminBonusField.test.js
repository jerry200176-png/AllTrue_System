// #4971: 行政加給 field — reason required, bounds, locked month is read-only.
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import ParttimeAdminBonusField from '../ParttimeAdminBonusField.vue';

const props = { branchId: 1, teacherId: 5, month: '2026-04' };
beforeEach(() => {
  vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ ok: true }) })));
});

describe('ParttimeAdminBonusField', () => {
  it('blocks save without a reason', async () => {
    const w = mount(ParttimeAdminBonusField, { props });
    await w.get('[data-testid="ab-amount"]').setValue('500');
    await w.get('[data-testid="ab-save"]').trigger('click');
    expect(w.get('[data-testid="ab-error"]').text()).toContain('原因');
    expect(fetch).not.toHaveBeenCalled();
  });

  it('PUTs amount + reason and emits saved', async () => {
    const w = mount(ParttimeAdminBonusField, { props });
    await w.get('[data-testid="ab-amount"]').setValue('500');
    await w.get('[data-testid="ab-reason"]').setValue('行政支援');
    await w.get('[data-testid="ab-save"]').trigger('click');
    await flushPromises();
    const [url, opts] = fetch.mock.calls[0];
    expect(url).toContain('/finance/parttime-payroll/admin-bonus');
    expect(opts.method).toBe('PUT');
    expect(JSON.parse(opts.body)).toMatchObject({ teacher_id: 5, month: '2026-04', amount: 500, reason: '行政支援' });
    expect(w.emitted('saved')).toHaveLength(1);
  });

  it('is read-only when the month is locked', () => {
    const w = mount(ParttimeAdminBonusField, { props: { ...props, locked: true, amount: 300, reason: 'x' } });
    expect(w.find('[data-testid="ab-save"]').exists()).toBe(false);
    expect(w.get('[data-testid="ab-amount"]').attributes('disabled')).toBeDefined();
  });
});
