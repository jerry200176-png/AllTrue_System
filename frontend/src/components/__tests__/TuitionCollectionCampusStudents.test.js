import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';
import { h, KeepAlive, ref } from 'vue';
import TuitionCollectionPage from '../../pages/TuitionCollectionPage.vue';

const wrappers = [];
const pageSlots = { renderStubDefaultSlot: true };
afterEach(() => { wrappers.splice(0).forEach(w => w.unmount()); vi.unstubAllGlobals(); localStorage.clear(); });

function rosterApi() {
  const state = { status: 'active' };
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'synthetic-token', user: { role: 'director' } }));
  const fetcher = vi.fn(async input => {
    const url = new URL(String(input), 'http://synthetic.local');
    return { ok: true, json: async () => url.pathname === '/api/v1/students'
      ? [{ id: 99, name: '合成學生', status: state.status }] : [] };
  });
  vi.stubGlobal('fetch', fetcher);
  const requests = () => fetcher.mock.calls.map(([input]) => new URL(String(input), 'http://synthetic.local'))
    .filter(url => url.pathname === '/api/v1/students');
  return { state, requests };
}
const roster = w => w.findComponent({ name: 'BillList' }).props('students');

describe('in-app #549 roster status reaches the billing list', () => {
  it.each([
    { student_id: 99, class_id: null },
    { student_id: 99, class_id: 42 },
  ])('#4148 routes document lookup to the exact student/course without leaving the bill list: %j', async (row) => {
    const { requests } = rosterApi();
    const w = shallowMount(TuitionCollectionPage, { props: { branchId: 7 }, global: pageSlots }); wrappers.push(w);
    await flushPromises();
    const list = w.findComponent({ name: 'BillList' }); const uid = list.vm.$.uid;
    list.vm.$emit('documents', row); await flushPromises();
    const ledger = w.findComponent({ name: 'AccountingLedgerModal' });
    expect(ledger.props()).toMatchObject({ show: true, branchId: 7, initialAction: 'documents', studentClassId: row.class_id, studentId: row.class_id ? null : 99, studentWide: row.class_id == null });
    ledger.vm.$emit('close'); await flushPromises();
    expect(w.findComponent({ name: 'BillList' }).vm.$.uid).toBe(uid);
    expect(requests()).toHaveLength(1);
  });
  it('passes pause metadata without removing the student from other campus pickers', async () => {
    const { state } = rosterApi(); state.status = 'paused';
    const w = shallowMount(TuitionCollectionPage, { props: { branchId: 1 }, global: pageSlots }); wrappers.push(w);
    await flushPromises();
    expect(roster(w)).toEqual([{ id: 99, name: '合成學生', status: 'paused' }]);
  });

  it('refreshes roster status with the existing refresh button', async () => {
    const { state, requests } = rosterApi();
    const w = shallowMount(TuitionCollectionPage, { props: { branchId: 1 }, global: { ...pageSlots, stubs: { PageHeader: false, Button: false } } }); wrappers.push(w);
    await flushPromises(); expect(requests()).toHaveLength(1);
    state.status = 'paused';
    await w.get('[aria-label="重新整理"]').trigger('click'); await flushPromises();
    expect(requests()).toHaveLength(2);
    expect(roster(w)[0].status).toBe('paused');
  });

  it('reads the new campus once per switch', async () => {
    const { requests } = rosterApi();
    const w = shallowMount(TuitionCollectionPage, { props: { branchId: 1 }, global: pageSlots }); wrappers.push(w);
    await flushPromises(); await w.setProps({ branchId: 2 }); await flushPromises();
    expect(requests().map(url => url.searchParams.get('branch_id'))).toEqual(['1', '2']);
  });

  it('refreshes when the kept-alive page returns without duplicating the first load', async () => {
    const { state, requests } = rosterApi(); const show = ref(true);
    const w = shallowMount({ setup: () => () => h(KeepAlive, null, () => show.value ? h(TuitionCollectionPage, { branchId: 1 }) : null) },
      { global: { stubs: { KeepAlive: false, TuitionCollectionPage: false }, renderStubDefaultSlot: true } }); wrappers.push(w);
    await flushPromises(); expect(requests()).toHaveLength(1);
    show.value = false; await flushPromises(); state.status = 'paused'; show.value = true; await flushPromises();
    expect(requests()).toHaveLength(2);
    expect(roster(w)[0].status).toBe('paused');
  });
});
