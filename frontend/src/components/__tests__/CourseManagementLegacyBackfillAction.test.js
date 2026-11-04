// In-app #490: a legacy-paid row tells a director 補登收款 and opens the ledger in back-fill mode.
import { describe, it, expect, vi, afterEach } from 'vitest';
import { shallowMount, flushPromises } from '@vue/test-utils';
import CourseManagement from '../../pages/CourseManagement.vue';
import { LEGACY_FLAG_ACTION_HELP } from '../../lib/courseMoneyState.js';

let wrapper;
afterEach(() => { wrapper?.unmount(); vi.unstubAllGlobals(); });
async function open(userRole) {
  vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 503, json: async () => ({}), text: async () => '{}' })));
  wrapper = shallowMount(CourseManagement, { props: { branchId: 7, userRole } });
  await flushPromises();
  return wrapper.vm.$.setupState;
}
const legacy = { id: 700001, student_id: 700001, subject: 'math', paid: true, legacy_paid_flag_only: true };

describe('舊資料標已收的課程列 (in-app #490)', () => {
  it('director: button reads 補登收款 and opens the ledger with legacy-backfill', async () => {
    const s = await open('director');
    expect(s.paymentNextActionLabel(legacy)).toBe('補登收款');
    expect(s.paymentStatusHelpTitle(legacy)).toBe(LEGACY_FLAG_ACTION_HELP);
    s.onPaymentNextAction(legacy);
    expect(s.ledgerInitialAction).toBe('legacy-backfill');
    expect(s.ledgerOpen).toBe(true);
  });

  it('non-director keeps 在這裡看帳務 and a plain ledger open', async () => {
    const s = await open('teacher');
    expect(s.paymentNextActionLabel(legacy)).toBe('在這裡看帳務');
    s.onPaymentNextAction(legacy);
    expect(s.ledgerInitialAction).toBeNull();
  });
});
