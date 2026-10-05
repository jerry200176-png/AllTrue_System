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
    const [url, init] = fetch.mock.calls[0];
    expect(url).toBe('/x');
    expect(init).toMatchObject({ method: 'POST', credentials: 'include', body: '{}' });
    expect(init.headers.get('Accept')).toBe('application/json');
    expect(init.headers.get('Authorization')).toBe('Bearer tok');
  });

  it('keeps Headers instances and tuple arrays from the caller', async () => {
    await authedFetch('/x', { headers: new Headers({ Accept: 'application/json' }) }, 't1');
    expect(fetch.mock.calls[0][1].headers.get('Accept')).toBe('application/json');
    await authedFetch('/x', { headers: [['Content-Type', 'application/json']] }, 't2');
    expect(fetch.mock.calls[1][1].headers.get('Content-Type')).toBe('application/json');
    expect(fetch.mock.calls[1][1].headers.get('Authorization')).toBe('Bearer t2');
  });

  it('uses a caller-supplied token without touching the session', async () => {
    await authedFetch('/x', {}, 'given');
    expect(getSession).not.toHaveBeenCalled();
    expect(fetch.mock.calls[0][1].headers.get('Authorization')).toBe('Bearer given');
  });

  it('keeps the legacy "Bearer undefined" when no session exists', async () => {
    getSession.mockResolvedValue({ data: { session: null } });
    expect(await getAccessToken()).toBeUndefined();
    await authedFetch('/x');
    expect(fetch.mock.calls[0][1].headers.get('Authorization')).toBe('Bearer undefined');
  });
});
