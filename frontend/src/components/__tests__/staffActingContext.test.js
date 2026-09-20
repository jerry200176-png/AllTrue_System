import { describe, expect, it } from 'vitest';
import {
  ACTING_AS_HEADER,
  actingAsHeaders,
  canSwitchStaffMode,
  installActingAsFetchBridge,
  normalizeActingAs,
  readStoredActingAs,
  writeStoredActingAs,
} from '../../lib/staffActingContext.js';
describe('staffActingContext', () => {
  it('normalizes only director/teacher', () => {
    expect(normalizeActingAs('Director')).toBe('director');
    expect(normalizeActingAs('teacher')).toBe('teacher');
    expect(normalizeActingAs('super_admin')).toBe(null);
    expect(normalizeActingAs('')).toBe(null);
  });
  it('persists acting_as and builds context header only when set', () => {
    const store = new Map();
    const storage = {
      getItem: (k) => (store.has(k) ? store.get(k) : null),
      setItem: (k, v) => { store.set(k, String(v)); },
      removeItem: (k) => { store.delete(k); },
    };
    expect(actingAsHeaders(null)).toEqual({});
    writeStoredActingAs('teacher', storage);
    expect(readStoredActingAs(storage)).toBe('teacher');
    expect(actingAsHeaders(readStoredActingAs(storage))).toEqual({
      [ACTING_AS_HEADER]: 'teacher',
    });
    writeStoredActingAs(null, storage);
    expect(readStoredActingAs(storage)).toBe(null);
  });
  it('mode switch only when both capabilities are present', () => {
    expect(canSwitchStaffMode(['director', 'teacher'])).toBe(true);
    expect(canSwitchStaffMode(['director'])).toBe(false);
    expect(canSwitchStaffMode([])).toBe(false);
    expect(canSwitchStaffMode(null)).toBe(false);
  });
  it('adds the selected context to direct same-origin API calls only', () => {
    const store = new Map([
      ['alltrue_session', JSON.stringify({ access_token: 'synthetic' })],
      ['alltrue_acting_as', 'teacher'],
    ]);
    const storage = {
      getItem: (key) => (store.has(key) ? store.get(key) : null),
    };
    const calls = [];
    const target = {
      Headers,
      location: { origin: 'https://app.test', href: 'https://app.test/' },
      localStorage: storage,
      fetch: (...args) => {
        calls.push(args);
        return args;
      },
    };
    installActingAsFetchBridge(target);
    target.fetch('/api/v1/students', { headers: { Accept: 'application/json' } });
    expect(calls[0][1].headers.get(ACTING_AS_HEADER)).toBe('teacher');
    target.fetch('https://cdn.example.test/widget.js', { headers: {} });
    expect(calls[1][1].headers.get(ACTING_AS_HEADER)).toBe(null);
  });
});
