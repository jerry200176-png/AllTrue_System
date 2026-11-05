// Billing v3 step 6: 已結清 is the 已收 filter of the bill list. An old `tab=settled` deep link lands there.
import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';
import TuitionCollectionPage from '../../pages/TuitionCollectionPage.vue';
import BillList from '../tuition/BillList.vue';

afterEach(() => { vi.unstubAllGlobals(); localStorage.clear(); });

describe('old 已結清 deep link', () => {
  it('opens the bill list with the 已收 filter, with no separate settled panel or reminder bar', async () => {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'synthetic-token', user: { role: 'director' } }));
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ data: [] }) })));
    const w = shallowMount(TuitionCollectionPage, { props: { branchId: 1, initialTab: 'settled' } });
    await flushPromises();
    expect(w.find('#tuition-accounting-panel-students').exists()).toBe(true);
    expect(w.findComponent(BillList).props('presetFilter')).toEqual({ key: 'paid' });
    expect(w.find('#tuition-accounting-panel-settled').exists()).toBe(false);
    expect(w.find('[data-testid="tc-reminders"]').exists()).toBe(false);
    w.unmount();
  });
});
