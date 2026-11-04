// uiux #4659 step 7: 續課 on a course row opens the renewal form in the right-side sheet, no page jump.
import { describe, it, expect, vi, afterEach } from 'vitest';
import { shallowMount, mount, flushPromises } from '@vue/test-utils';
import CourseManagement from '../../pages/CourseManagement.vue';
import PurchaseSessionsModal from '../course-management/PurchaseSessionsModal.vue';

const authed = vi.hoisted(() => ({ authedFetch: vi.fn(), getAccessToken: vi.fn(async () => 'tok') }));
vi.mock('../../lib/authedFetch', async (orig) => ({ ...(await orig()), ...authed }));

let wrapper;
afterEach(() => { wrapper?.unmount(); vi.unstubAllGlobals(); document.body.innerHTML = ''; });
async function open() {
  vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 503, json: async () => ({}), text: async () => '{}' })));
  wrapper = shallowMount(CourseManagement, { props: { branchId: 7, userRole: 'director' } });
  await flushPromises();
  return wrapper.vm.$.setupState;
}
const session = { id: 810001, student_id: 810001, student_name: 'S', subject: 'math', class_type: 'regular', payment_type: 'session', sessions_purchased: 8, sessions_used: 8 };

describe('row 續課 opens in the side sheet', () => {
  it('plain session course: sheet placement, no navigate emit', async () => {
    const s = await open();
    s.openCommercialPurchaseEntry(session);
    expect(s.showPurchaseModal).toBe(true);
    expect(s.purchaseAsSheet).toBe(true);
    expect(wrapper.emitted('navigate')).toBeUndefined();
  });

  it('monthly and tutoring renewals keep their existing hop', async () => {
    const s = await open();
    s.openCommercialPurchaseEntry({ ...session, payment_type: 'monthly' });
    s.openCommercialPurchaseEntry({ ...session, class_type: 'tutoring' });
    expect(wrapper.emitted('navigate')).toHaveLength(2);
    expect(s.purchaseAsSheet).toBe(false);
  });

  it('other entries to the purchase form stay centered', async () => {
    const s = await open();
    s.openCommercialPurchaseEntry(session);
    s.showPurchaseModal = false;
    s.openPurchaseModal(session);
    expect(s.purchaseAsSheet).toBe(false);
  });
});

describe('sheet submit', () => {
  it('success closes the sheet, posts purchase-batch, and the list reloads quietly with no navigate', async () => {
    authed.authedFetch.mockImplementation(async (url) => ({
      ok: true, status: 200,
      json: async () => (String(url).includes('purchase-batch') ? { new_course: { created_sessions: 8 } } : {}),
    }));
    const s = await open();
    s.openCommercialPurchaseEntry(session);
    authed.authedFetch.mockClear();
    await s.submitPurchaseSessions();
    await flushPromises();
    const urls = authed.authedFetch.mock.calls.map((c) => c[0]);
    expect(urls[0]).toBe('/api/v1/student-classes/810001/purchase-batch');
    expect(JSON.parse(authed.authedFetch.mock.calls[0][1].body)).toMatchObject({ sessions: 8, mode: 'new_purchase' });
    expect(s.showPurchaseModal).toBe(false);
    expect(wrapper.emitted('navigate')).toBeUndefined();
  });

  it('failure keeps the sheet open', async () => {
    authed.authedFetch.mockImplementation(async () => ({ ok: false, status: 422, json: async () => ({ message: 'x' }) }));
    vi.stubGlobal('alert', vi.fn());
    const s = await open();
    s.openCommercialPurchaseEntry(session);
    await s.submitPurchaseSessions();
    expect(s.showPurchaseModal).toBe(true);
  });
});

describe('PurchaseSessionsModal placement="right"', () => {
  const form = { sessions: 8, start_date: '2026-11-05', student_name: 'S', subject: 'Math', package_op: 'add' };
  const mountSheet = () => mount(PurchaseSessionsModal, { props: { show: true, placement: 'right', form }, attachTo: document.body });

  it('renders inside the sheet; 確認續課 emits submit, 取消 emits close, state kept', async () => {
    wrapper = mountSheet();
    const panel = document.querySelector('.at-dialog__panel--sheet');
    expect(panel).not.toBeNull();
    expect(panel.textContent).toContain('確認續課');
    [...panel.querySelectorAll('button')].find((b) => b.textContent.includes('確認續課')).click();
    [...panel.querySelectorAll('button')].find((b) => b.textContent.trim() === '取消').click();
    await flushPromises();
    expect(wrapper.emitted('submit')).toHaveLength(1);
    expect(wrapper.emitted('close')).toHaveLength(1);
    expect(form.sessions).toBe(8);
  });

  it('default placement is unchanged (centered modal)', () => {
    wrapper = mount(PurchaseSessionsModal, { props: { show: true, form }, attachTo: document.body });
    expect(wrapper.find('.modal-overlay').exists()).toBe(true);
    expect(document.querySelector('.at-dialog__panel--sheet')).toBeNull();
  });
});
