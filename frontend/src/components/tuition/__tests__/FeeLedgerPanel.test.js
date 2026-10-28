import { mount, flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import FeeLedgerPanel from '../FeeLedgerPanel.vue';

const mockFetch = vi.fn();
vi.mock('../../../lib/authedFetch', () => ({
  getAccessToken: async () => 'tok',
  authedFetch: (...a) => mockFetch(...a),
}));
const toast = { success: vi.fn(), error: vi.fn() };
vi.mock('../../../composables/useToast', () => ({ useToast: () => toast }));

const ledger = {
  charges: [
    { id: 7, student_name: '王小明', category: 'books', category_label: '書籍費', title: '講義', amount: 350, charged_on: '2026-10-28', status: 'unpaid' },
    { id: 8, student_name: '李小華', category: 'books', category_label: '書籍費', title: '講義', amount: 350, charged_on: '2026-10-28', status: 'paid', paid_on: '2026-10-28', paid_method: 'cash' },
  ],
  payouts: [{ id: 3, category_label: '書籍費', title: '書商', amount: 600, paid_on: '2026-10-27', status: 'active' }],
  summary: {
    rows: [{ category: 'books', label: '書籍費', charged: 700, collected: 350, outstanding: 350, paid_out: 600, balance: -250 }],
    total: { charged: 700, collected: 350, outstanding: 350, paid_out: 600, balance: -250 },
  },
  categories: { books: '書籍費', materials: '教材費', sundry: '雜費', other: '其他' },
  methods: { cash: '現金', transfer: '匯款' },
};
const students = [{ id: 1, name: '王小明' }, { id: 2, name: '李小華' }, { id: 3, name: '王大同' }];
const res = (body, ok = true, status = 200) => ({ ok, status, json: async () => body });
let wrapper;
const mountIt = (props = { branchId: 5, students }) => mount(FeeLedgerPanel, { props, global: { stubs: { teleport: true } } });
// Exact label; an icon button's text starts with its material-symbols ligature name (e.g. "add").
const btn = (t) => wrapper.findAll('button').find((b) => b.text().trim().replace(/^[a-z_]+/, '').trim() === t);
const posts = () => mockFetch.mock.calls.filter(([, init]) => init?.method === 'POST').map(([u, init]) => [u, JSON.parse(init.body)]);

beforeEach(() => {
  mockFetch.mockReset();
  toast.success.mockReset();
  toast.error.mockReset();
  mockFetch.mockImplementation(async (url, init) => (init?.method === 'POST' ? res({ charges: [{}, {}] }, true, 201) : res(ledger)));
});
afterEach(() => wrapper?.unmount());

it('asks for a campus first and loads nothing without one', async () => {
  wrapper = mountIt({ branchId: null, students });
  await flushPromises();
  expect(wrapper.text()).toContain('請先選擇分校');
  expect(mockFetch).not.toHaveBeenCalled();
});

it('shows the month summary, charges and payouts kept apart from tuition', async () => {
  wrapper = mountIt();
  await flushPromises();
  expect(mockFetch.mock.calls[0][0]).toMatch(/^\/api\/v1\/fee-ledger\?campus_id=5&month=\d{4}-\d{2}$/);
  const t = wrapper.text();
  expect(t).toContain('不是學費');
  expect(t).toContain('NT$ -250');
  expect(t).toContain('代收款（2）');
  expect(t).toContain('已收（2026-10-28 現金）');
  expect(t).toContain('代付（1）');
});

it('charges the searched students in one request, with tri-state select-all', async () => {
  wrapper = mountIt();
  await flushPromises();
  await btn('新增代收款').trigger('click');
  await wrapper.find('input[type=search]').setValue('王');
  const all = () => wrapper.find('fieldset label input[type=checkbox]');
  await all().setValue(true);
  expect(wrapper.text()).toContain('已選 2 位');
  await wrapper.findAll('fieldset .flp__list input')[0].setValue(false);
  expect(all().element.indeterminate).toBe(true);
  await wrapper.findAll('fieldset .flp__list input')[0].setValue(true);
  await wrapper.find('input[maxlength="60"]').setValue('國二講義');
  await wrapper.find('input[type=number]').setValue('350');
  expect(wrapper.text()).toContain('會建立 2 筆，共 NT$ 700');
  await btn('確定').trigger('click');
  await flushPromises();
  expect(posts()[0][0]).toBe('/api/v1/fee-charges');
  expect(posts()[0][1]).toMatchObject({ campus_id: 5, category: 'books', title: '國二講義', amount: 350 });
  expect([...posts()[0][1].student_ids].sort()).toEqual([1, 3]);
  expect(toast.success).toHaveBeenCalledWith('已建立 2 筆代收款');
});

it('records a payment and shows the server refusal when it fails', async () => {
  wrapper = mountIt();
  await flushPromises();
  await btn('收款').trigger('click');
  await btn('確定').trigger('click');
  await flushPromises();
  expect(posts()[0][0]).toBe('/api/v1/fee-charges/7/pay');
  expect(posts()[0][1]).toMatchObject({ method: 'cash' });

  mockFetch.mockImplementation(async (url, init) => (init?.method === 'POST' ? res({ message: '這筆代收款已經收過了。' }, false, 409) : res(ledger)));
  await btn('收款').trigger('click');
  await btn('確定').trigger('click');
  await flushPromises();
  expect(toast.error).toHaveBeenCalledWith('這筆代收款已經收過了。');
});

it('drops a slow reply for the previous campus and clears its rows on switch', async () => {
  const pending = {};
  mockFetch.mockImplementation((url) => new Promise((resolve) => { pending[new URLSearchParams(url.split('?')[1]).get('campus_id')] = resolve; }));
  wrapper = mountIt();
  await flushPromises();
  await wrapper.setProps({ branchId: 6 });
  await flushPromises();
  expect(wrapper.text()).not.toContain('王小明');
  pending['6'](res({ ...ledger, charges: [{ ...ledger.charges[1], id: 9, student_name: '六號分校學生' }], payouts: [] }));
  await flushPromises();
  pending['5'](res(ledger)); // campus 5 answers last
  await flushPromises();
  expect(wrapper.text()).toContain('六號分校學生');
  expect(wrapper.text()).not.toContain('王小明');
});
