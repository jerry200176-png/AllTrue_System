import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import TriageTokenPanel from '../TriageTokenPanel.vue';

const row = { id: 7, name: 'Claude 雲端分診', created_at: '2026-10-13T08:00:00+08:00', last_used_at: null, expires_at: '2027-01-11T08:00:00+08:00', expired: false };

function stubApi() {
  let rows = [];
  const calls = [];
  const fetchMock = vi.fn(async (url, init = {}) => {
    const method = init.method || 'GET';
    calls.push(`${method} ${url}`);
    let body = { data: rows, ttl_days: 90 };
    if (method === 'POST') {
      rows = [row];
      body = { token: '7|alltrue_triage_SECRET', record: row };
    } else if (method === 'DELETE') {
      rows = [];
      body = { revoked: true, id: 7 };
    }
    return { ok: true, status: 200, json: async () => body };
  });
  vi.stubGlobal('fetch', fetchMock);
  return calls;
}

afterEach(() => vi.unstubAllGlobals());

describe('TriageTokenPanel', () => {
  it('shows the new token once, then only metadata stays on screen', async () => {
    const calls = stubApi();
    const w = mount(TriageTokenPanel, { props: { token: 'session' } });
    await flushPromises();
    expect(w.text()).toContain('目前沒有任何金鑰');

    await w.get('[data-testid="triage-token-name"]').setValue('Claude 雲端分診');
    await w.get('form').trigger('submit');
    await flushPromises();

    expect(calls).toContain('POST /api/v1/admin/triage-tokens');
    expect(w.get('[data-testid="triage-token-secret"]').element.value).toBe('7|alltrue_triage_SECRET');
    expect(w.text()).toContain('金鑰只會顯示這一次');
    expect(w.findAll('[data-testid="triage-token-row"]')).toHaveLength(1);
    expect(w.text()).toContain('尚未使用');

    await w.get('[data-testid="triage-token-dismiss"]').trigger('click');
    expect(w.find('[data-testid="triage-token-secret"]').exists()).toBe(false);
    expect(w.html()).not.toContain('alltrue_triage_SECRET');
  });

  it('copies the token and revokes only after an explicit confirm', async () => {
    const calls = stubApi();
    const writeText = vi.fn(async () => {});
    vi.stubGlobal('navigator', { clipboard: { writeText } });
    const w = mount(TriageTokenPanel, { props: { token: 'session' } });
    await flushPromises();
    await w.get('[data-testid="triage-token-name"]').setValue('x');
    await w.get('form').trigger('submit');
    await flushPromises();

    await w.get('[data-testid="triage-token-copy"]').trigger('click');
    await flushPromises();
    expect(writeText).toHaveBeenCalledWith('7|alltrue_triage_SECRET');
    expect(w.get('[data-testid="triage-token-copy"]').text()).toBe('已複製');

    await w.get('[data-testid="triage-token-revoke"]').trigger('click');
    expect(calls.some((c) => c.startsWith('DELETE'))).toBe(false);
    await w.get('[data-testid="triage-token-confirm"]').trigger('click');
    await flushPromises();
    expect(calls).toContain('DELETE /api/v1/admin/triage-tokens/7');
    expect(w.findAll('[data-testid="triage-token-row"]')).toHaveLength(0);
  });
});
