import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import BindingConflictReviewPage from '../../pages/BindingConflictReviewPage.vue';
import BindingManagementPage from '../../pages/BindingManagementPage.vue';

const json = (body) => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(body) });

// Shapes copied from BindingConflictController::index/resolve and BindingController::index (?page=).
const conflict = {
  id: 7, student_id: 7, student_name: 'S7', campus_count: 2,
  bindings: [
    { id: 11, campus_id: 1, campus_name: 'C1', line_user_id_masked: 'U1111111…aaaa', bound_at: '2026-10-01T00:00:00+08:00' },
    { id: 12, campus_id: 2, campus_name: 'C2', line_user_id_masked: 'U2222222…bbbb', bound_at: '2026-10-02T00:00:00+08:00' },
  ],
};

afterEach(() => vi.unstubAllGlobals());

describe('binding pages follow the backend response contract (#3936)', () => {
  it('conflict page renders {conflicts} rows and posts {action, binding_id}', async () => {
    const calls = [];
    vi.stubGlobal('fetch', vi.fn((url, opts = {}) => {
      calls.push({ url, opts });
      return json(opts.method === 'POST' ? { message: 'ok', result: {} } : { conflicts: [conflict] });
    }));
    const w = mount(BindingConflictReviewPage, { props: { userRole: 'super_admin' }, attachTo: document.body });
    await flushPromises();
    expect(w.findAll('tbody tr')).toHaveLength(1);
    expect(w.text()).toContain('S7');
    expect(w.text()).toContain('C1');

    await w.find('.bcrp-line-chip button').trigger('click');
    await flushPromises();
    const confirm = [...document.body.querySelectorAll('button')].find((b) => b.textContent.includes('確認保留'));
    confirm.click();
    await flushPromises();
    const post = calls.find((c) => c.opts.method === 'POST');
    expect(post.url).toContain('/bindings/conflicts/7/resolve');
    expect(JSON.parse(post.opts.body)).toEqual({ action: 'keep', binding_id: 11 });
    w.unmount();
  });

  it('a director (keep is super_admin only) sends action delete', async () => {
    const calls = [];
    vi.stubGlobal('fetch', vi.fn((url, opts = {}) => {
      calls.push({ url, opts });
      return json(opts.method === 'POST' ? { message: 'ok' } : { conflicts: [conflict] });
    }));
    const w = mount(BindingConflictReviewPage, { props: { userRole: 'director', branchId: 1 }, attachTo: document.body });
    await flushPromises();
    await w.find('.bcrp-line-chip button').trigger('click');
    await flushPromises();
    [...document.body.querySelectorAll('button')].find((b) => b.textContent.includes('確認解除')).click();
    await flushPromises();
    const post = calls.find((c) => c.opts.method === 'POST');
    expect(JSON.parse(post.opts.body)).toEqual({ action: 'delete', binding_id: 11 });
    w.unmount();
  });

  it('list page renders the paged {data, meta} rows', async () => {
    vi.stubGlobal('fetch', vi.fn((url) => json(String(url).includes('/stats')
      ? {}
      : { data: [{ id: 5, student_id: 3, student_name: 'S3', campus_id: 1, line_user_id_masked: 'U3333333…cccc' }], meta: { current_page: 1, last_page: 1, total: 1 } })));
    const w = mount(BindingManagementPage, { props: { userRole: 'super_admin' } });
    await flushPromises();
    expect(w.text()).toContain('S3');
    expect(w.text()).toContain('U3333333…cccc');
  });
});
