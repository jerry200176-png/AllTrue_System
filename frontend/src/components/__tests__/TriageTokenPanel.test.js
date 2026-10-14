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

// Mirrors ProfileCenterPage: the parent owns v-model:secret / v-model:pending.
function mountPanel() {
  const w = mount(TriageTokenPanel, {
    props: {
      token: 'session',
      secret: '',
      'onUpdate:secret': (v) => w.setProps({ secret: v }),
    },
  });
  return w;
}

describe('TriageTokenPanel', () => {
  it('shows the new token once, then only metadata stays on screen', async () => {
    const calls = stubApi();
    const w = mountPanel();
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
    const w = mountPanel();
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

  it('emits pending around the POST and hands the secret to the parent', async () => {
    stubApi();
    const w = mountPanel();
    await flushPromises();
    await w.get('[data-testid="triage-token-name"]').setValue('x');
    await w.get('form').trigger('submit');
    expect(w.emitted('update:pending')[0]).toEqual([true]);
    await flushPromises();
    expect(w.emitted('update:pending').at(-1)).toEqual([false]);
    expect(w.emitted('update:secret').at(-1)).toEqual(['7|alltrue_triage_SECRET']);
  });

  it('shows the error without the empty state when listing fails', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 500, json: async () => ({ message: 'boom' }) })));
    const w = mountPanel();
    await flushPromises();
    expect(w.text()).toContain('boom');
    expect(w.find('[data-testid="triage-token-empty"]').exists()).toBe(false);
  });

  it('wraps the table in a horizontally scrollable container', async () => {
    stubApi();
    const w = mountPanel();
    await flushPromises();
    await w.get('[data-testid="triage-token-name"]').setValue('x');
    await w.get('form').trigger('submit');
    await flushPromises();
    expect(w.get('[data-testid="triage-token-table-wrap"]').find('table').exists()).toBe(true);
  });
  it('shows a ttl_days of 0 from the server instead of the 90-day default', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, status: 200, json: async () => ({ data: [], ttl_days: 0 }) })));
    const w = mountPanel();
    await flushPromises();
    expect(w.text()).toContain('建立後 0 天自動失效');
  });

  it('blocks a second create until the shown secret is dismissed', async () => {
    const calls = stubApi();
    const w = mountPanel();
    await flushPromises();
    await w.get('[data-testid="triage-token-name"]').setValue('x');
    await w.get('form').trigger('submit');
    await flushPromises();

    expect(w.get('[data-testid="triage-token-create"]').attributes('disabled')).toBeDefined();
    expect(w.get('[data-testid="triage-token-name"]').attributes('disabled')).toBeDefined();
    expect(w.find('[data-testid="triage-token-create-blocked"]').exists()).toBe(true);
    await w.get('form').trigger('submit');
    await flushPromises();
    expect(calls.filter((c) => c.startsWith('POST'))).toHaveLength(1);

    await w.get('[data-testid="triage-token-dismiss"]').trigger('click');
    await w.get('[data-testid="triage-token-name"]').setValue('y');
    expect(w.get('[data-testid="triage-token-create"]').attributes('disabled')).toBeUndefined();
  });

  it('clears the on-screen secret when that same token is revoked', async () => {
    stubApi();
    const w = mountPanel();
    await flushPromises();
    await w.get('[data-testid="triage-token-name"]').setValue('x');
    await w.get('form').trigger('submit');
    await flushPromises();
    expect(w.find('[data-testid="triage-token-secret"]').exists()).toBe(true);

    await w.get('[data-testid="triage-token-revoke"]').trigger('click');
    await w.get('[data-testid="triage-token-confirm"]').trigger('click');
    await flushPromises();
    expect(w.emitted('update:secret').at(-1)).toEqual(['']);
    expect(w.html()).not.toContain('alltrue_triage_SECRET');
  });

  it('clears the secret on revoke after a tab-switch remount (id comes from the parent-owned secret)', async () => {
    stubApi();
    const first = mountPanel();
    await flushPromises();
    await first.get('[data-testid="triage-token-name"]').setValue('x');
    await first.get('form').trigger('submit');
    await flushPromises();
    const kept = first.emitted('update:secret').at(-1)[0];
    first.unmount();

    const emitted = [];
    const w = mount(TriageTokenPanel, { props: { token: 'session', secret: kept, 'onUpdate:secret': (v) => emitted.push(v) } });
    await flushPromises();
    expect(w.find('[data-testid="triage-token-secret"]').exists()).toBe(true);
    await w.get('[data-testid="triage-token-revoke"]').trigger('click');
    await w.get('[data-testid="triage-token-confirm"]').trigger('click');
    await flushPromises();
    expect(emitted.at(-1)).toBe('');
  });

  it('drops a stale list response that finishes after the post-create refresh', async () => {
    let releaseFirst;
    let getCount = 0;
    vi.stubGlobal('fetch', vi.fn(async (url, init = {}) => {
      const method = init.method || 'GET';
      if (method === 'POST') return { ok: true, status: 201, json: async () => ({ token: '7|alltrue_triage_SECRET', record: row }) };
      getCount += 1;
      if (getCount === 1) {
        await new Promise((r) => { releaseFirst = r; });
        return { ok: true, status: 200, json: async () => ({ data: [], ttl_days: 90 }) };
      }
      return { ok: true, status: 200, json: async () => ({ data: [row], ttl_days: 90 }) };
    }));
    const w = mountPanel();
    await flushPromises();
    await w.get('[data-testid="triage-token-name"]').setValue('x');
    await w.get('form').trigger('submit');
    await flushPromises();
    expect(w.findAll('[data-testid="triage-token-row"]')).toHaveLength(1);

    releaseFirst();
    await flushPromises();
    expect(w.findAll('[data-testid="triage-token-row"]')).toHaveLength(1);
  });

  it('treats a 2xx create without a token as an error and emits no secret', async () => {
    vi.stubGlobal('fetch', vi.fn(async (url, init = {}) => (init.method === 'POST'
      ? { ok: true, status: 201, json: async () => { throw new SyntaxError('bad json'); } }
      : { ok: true, status: 200, json: async () => ({ data: [], ttl_days: 90 }) })));
    const w = mountPanel();
    await flushPromises();
    await w.get('[data-testid="triage-token-name"]').setValue('x');
    await w.get('form').trigger('submit');
    await flushPromises();
    expect(w.emitted('update:secret')).toBeUndefined();
    expect(w.text()).toContain('伺服器回應格式錯誤');
  });

  it('treats a 2xx list without a data array as an error, not an empty inventory', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, status: 200, json: async () => ({ ttl_days: 90 }) })));
    const w = mountPanel();
    await flushPromises();
    expect(w.text()).toContain('伺服器回應格式錯誤');
    expect(w.find('[data-testid="triage-token-empty"]').exists()).toBe(false);
  });
});
