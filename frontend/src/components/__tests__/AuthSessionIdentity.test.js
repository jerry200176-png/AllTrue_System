import { existsSync, readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import {
  getSessionUserId,
  isCurrentAuthRevision,
  isLocallyCorruptSession,
  shouldClearLocalIdentity,
} from '../../lib/authSessionIdentity.js';

const valid = { user: { id: 'user-a' }, access_token: 'token-a' };

describe('auth session identity', () => {
  it('fails closed for a damaged local session but accepts a valid identity', () => {
    expect(getSessionUserId(valid)).toBe('user-a');
    expect(getSessionUserId({ user: { id: 42 } })).toBe('42');
    expect(isLocallyCorruptSession(valid)).toBe(false);
    expect(isLocallyCorruptSession({ user: { id: 42 } })).toBe(false);
    expect(isLocallyCorruptSession({ user: {} })).toBe(true);
    expect(isLocallyCorruptSession({})).toBe(true);
  });

  it('only clears signed-out or 401 identities, not offline, 403, or 5xx responses', () => {
    expect(shouldClearLocalIdentity({ event: 'SIGNED_OUT', session: null })).toBe(true);
    expect(shouldClearLocalIdentity({ responseStatus: 401, session: valid })).toBe(true);
    expect(shouldClearLocalIdentity({ responseStatus: 0, session: valid })).toBe(false);
    expect(shouldClearLocalIdentity({ responseStatus: 403, session: valid })).toBe(false);
    expect(shouldClearLocalIdentity({ responseStatus: 500, session: valid })).toBe(false);
  });

  it('does not let a late response from an earlier login clear the new login', () => {
    expect(isCurrentAuthRevision(4, 4)).toBe(true);
    expect(isCurrentAuthRevision(4, 5)).toBe(false);
  });

  it('uses guarded local cleanup in bootstrap, auth events, and profile refresh', () => {
    const appPath = existsSync(`${process.cwd()}/src/App.vue`)
      ? `${process.cwd()}/src/App.vue`
      : `${process.cwd()}/frontend/src/App.vue`;
    const app = readFileSync(appPath, 'utf8');
    expect(app).toContain("supabase.auth.signOut({ scope: 'local' })");
    expect(app).toContain('shouldClearLocalIdentity({ session: data?.session })');
    expect(app).toContain('shouldClearLocalIdentity({ event, session: nextSession })');
    expect(app).toContain('shouldClearLocalIdentity({ responseStatus: res.status, session: session.value })');
    expect(app).toContain('if (!isCurrentAuth(revision) || getSessionUserId(session.value) !== _uid) return;');
    expect(app).toContain('void fetchProfile(getSessionUserId(data.session), revision);');
  });
});
