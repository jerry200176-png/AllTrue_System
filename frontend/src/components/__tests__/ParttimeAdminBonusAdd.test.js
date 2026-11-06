// #4971: teachers with no sessions can still get a 行政加給 — pick from this campus's part-time teachers.
import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import ParttimeAdminBonusAdd from '../ParttimeAdminBonusAdd.vue';

describe('ParttimeAdminBonusAdd', () => {
  it('lists only part-time teachers not already in the table, then shows the field', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ data: [
      { id: 1, name: '甲', employment_type: 'part_time' },
      { id: 2, name: '乙', employment_type: 'part_time' },
      { id: 3, name: '丙', employment_type: 'full_time' },
    ] }) })));
    const w = mount(ParttimeAdminBonusAdd, { props: { branchId: 1, month: '2026-04', existingIds: [1] } });
    await flushPromises();
    expect(fetch.mock.calls[0][0]).toContain('/teachers?branch_id=1');
    const opts = w.findAll('[data-testid="ab-add-select"] option').map((o) => o.text());
    expect(opts).toEqual(['選擇老師', '乙']);
    expect(w.find('[data-testid="admin-bonus"]').exists()).toBe(false);
    await w.get('[data-testid="ab-add-select"]').setValue(2);
    expect(w.find('[data-testid="admin-bonus"]').exists()).toBe(true);
  });
});
