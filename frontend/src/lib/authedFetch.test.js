import { describe, it, expect, vi, beforeEach } from 'vitest';

const getSession = vi.fn();
vi.mock('../supabase', () => ({ supabase: { auth: { getSession: (...a) => getSession(...a) } } }));

import { authedFetch, getAccessToken } from './authedFetch';

describe('authedFetch', () => {
  beforeEach(() => {
    getSession.mockReset();
    globalThis.fetch = vi.fn().mockResolvedValue({ ok: true });
  });

  it('reads the session token and merges Authorization into caller headers', async () => {
    getSession.mockResolvedValue({ data: { session: { access_token: 'tok' } } });
    const res = await authedFetch('/x', { method: 'POST', credentials: 'include', headers: { Accept: 'application/json' }, body: '{}' });
    expect(res).toEqual({ ok: true });
    expect(fetch).toHaveBeenCalledWith('/x', {
      method: 'POST', credentials: 'include', body: '{}',
      headers: { Accept: 'application/json', Authorization: 'Bearer tok' },
    });
  });

  it('uses a caller-supplied token without touching the session', async () => {
    await authedFetch('/x', {}, 'given');
    expect(getSession).not.toHaveBeenCalled();
    expect(fetch.mock.calls[0][1].headers.Authorization).toBe('Bearer given');
  });

  it('keeps the legacy "Bearer undefined" when no session exists', async () => {
    getSession.mockResolvedValue({ data: { session: null } });
    expect(await getAccessToken()).toBeUndefined();
    await authedFetch('/x');
    expect(fetch.mock.calls[0][1].headers.Authorization).toBe('Bearer undefined');
  });
});
