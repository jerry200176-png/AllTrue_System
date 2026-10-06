import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

const authedFetch = vi.fn();
const getAccessToken = vi.fn();
vi.mock('../../../lib/authedFetch', () => ({
  authedFetch: (...a) => authedFetch(...a),
  getAccessToken: (...a) => getAccessToken(...a),
}));

import MonthlyBatchRenewModal from '../MonthlyBatchRenewModal.vue';

const res = (ok, json) => ({ ok, json: async () => json });
const JSON_HEADERS = { 'Content-Type': 'application/json', Accept: 'application/json' };
const courses = [{ id: 7, subject_name: '數學', teacher_name: '王', end_date: '2026-09-30' }];
const future = '2099-01-01';

async function mountOpen() {
  const w = mount(MonthlyBatchRenewModal, { props: { show: false, studentName: 'S', courses } });
  await w.setProps({ show: true });
  await flushPromises();
  return w;
}

beforeEach(() => {
  authedFetch.mockReset();
  getAccessToken.mockReset().mockResolvedValue('tok');
});

describe('MonthlyBatchRenewModal requests', () => {
  it('previews then renews each selected row through authedFetch with the resolved token', async () => {
    authedFetch.mockResolvedValueOnce(res(true, { proposed_course: { start_date: future } }));
    const w = await mountOpen();
    const [url, init, token] = authedFetch.mock.calls[0];
    expect(url).toBe('/api/v1/student-classes/7/renewal-preview');
    expect(init).toMatchObject({ method: 'POST', credentials: 'include', headers: JSON_HEADERS });
    expect(JSON.parse(init.body)).toMatchObject({ mode: 'renew_monthly' });
    expect(token).toBe('tok');
    const endDate = JSON.parse(init.body).end_date;

    authedFetch.mockResolvedValueOnce(res(true, {}));
    await w.find('button.primary').trigger('click');
    await flushPromises();
    expect(authedFetch).toHaveBeenLastCalledWith('/api/v1/student-classes/7/renew-monthly', {
      method: 'POST', credentials: 'include', headers: JSON_HEADERS,
      body: JSON.stringify({ end_date: endDate }),
    }, 'tok');
    expect(w.text()).toContain('已建立');
  });

  it('marks rows as error and sends nothing when there is no session token', async () => {
    getAccessToken.mockResolvedValue(undefined);
    const w = await mountOpen();
    expect(authedFetch).not.toHaveBeenCalled();
    expect(w.text()).toContain('請重新登入後再試');
  });
});
