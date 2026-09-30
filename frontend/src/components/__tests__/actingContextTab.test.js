import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import ActingContextChip from '../ActingContextChip.vue';
import {
  formatContextChipLabel,
  readActingAs,
  resolveRouteContext,
  writeActingAs,
} from '../../lib/staffActingContext.js';
import { getNavigationGroups, requiredContextForPage } from '../../lib/navigationRegistry.js';

const mem = () => {
  const m = new Map();
  return { getItem: (k) => (m.has(k) ? m.get(k) : null), setItem: (k, v) => m.set(k, String(v)), removeItem: (k) => m.delete(k) };
};
const BOTH = ['director', 'teacher'];

describe('per-tab acting context', () => {
  it('seeds a new tab from last-used and isolates later switches', () => {
    const last = mem();
    writeActingAs('teacher', mem(), last);
    const tabA = mem();
    const tabB = mem();
    expect(readActingAs(tabA, last)).toBe('teacher');
    expect(readActingAs(tabB, last)).toBe('teacher');
    writeActingAs('director', tabA, last);
    expect(readActingAs(tabA, last)).toBe('director');
    expect(readActingAs(tabB, last)).toBe('teacher');
    expect(last.getItem('alltrue_acting_as')).toBe('director');
  });
  it('null clears both layers', () => {
    const tab = mem(); const last = mem();
    writeActingAs('director', tab, last);
    writeActingAs(null, tab, last);
    expect(readActingAs(tab, last)).toBe(null);
  });
});

describe('resolveRouteContext', () => {
  it('switches only for multi-capability users on a page of the other context', () => {
    expect(resolveRouteContext({ required: 'director', active: 'teacher', capabilities: BOTH })).toEqual({ action: 'switch', context: 'director' });
    expect(resolveRouteContext({ required: 'director', active: 'director', capabilities: BOTH })).toEqual({ action: 'none' });
    expect(resolveRouteContext({ required: null, active: 'teacher', capabilities: BOTH })).toEqual({ action: 'none' });
  });
  it('is a no-op for single-capability users (no silent escalation)', () => {
    expect(resolveRouteContext({ required: 'director', active: 'teacher', capabilities: ['teacher'] })).toEqual({ action: 'none' });
    expect(resolveRouteContext({ required: 'director', active: 'teacher', capabilities: [] })).toEqual({ action: 'none' });
  });
});

describe('navigation requiredContext', () => {
  it('derives director-only, teacher-only and shared pages', () => {
    expect(requiredContextForPage('tuition-collect')).toBe('director');
    expect(requiredContextForPage('teachers')).toBe('director');
    expect(requiredContextForPage('branch-management')).toBe(null); // super_admin-only: never auto-switch
    expect(requiredContextForPage('teacher-home')).toBe('teacher');
    expect(requiredContextForPage('calendar')).toBe(null);
    const item = getNavigationGroups('director').flatMap((g) => g.items).find((i) => i.page === 'students');
    expect(item.requiredContext).toBe('director');
  });
});

describe('ActingContextChip', () => {
  it('renders label with campuses and emits switch', async () => {
    expect(formatContextChipLabel('director', [1, 2], { 1: '大安', 2: '信義' })).toBe('主任 · 大安、信義');
    const host = document.createElement('div');
    const seen = [];
    createApp(ActingContextChip, {
      context: 'teacher', campusMap: { teacher: [1] }, campusNames: { 1: '大安' }, onSwitch: (v) => seen.push(v),
    }).mount(host);
    expect(host.querySelector('[aria-live="polite"]').textContent).toBe('老師 · 大安');
    expect(host.querySelector('.acting-chip--teacher')).not.toBeNull();
    host.querySelector('.acting-chip').click();
    await nextTick();
    host.querySelectorAll('.acting-chip-option')[0].click();
    expect(seen).toEqual(['director']);
  });
});

describe('App manual switch', () => {
  it('syncs the URL to the new context before reloading /me (no snap-back, no toast)', () => {
    const src = readFileSync('src/App.vue', 'utf8');
    const body = src.slice(src.indexOf('async function switchStaffMode'), src.indexOf('const isPasswordChangeLocked'));
    const sync = body.indexOf("syncAppPageUrl(active.value, { mode: page ? 'push' : 'replace' })");
    expect(sync).toBeGreaterThan(-1);
    expect(sync).toBeLessThan(body.indexOf('await fetchProfile'));
    expect(body).not.toContain('toast');
    // after the sync the URL page is the new home, which needs no further switch
    expect(resolveRouteContext({ required: requiredContextForPage('director'), active: 'director', capabilities: BOTH })).toEqual({ action: 'none' });
    expect(resolveRouteContext({ required: requiredContextForPage('teacher-home'), active: 'teacher', capabilities: BOTH })).toEqual({ action: 'none' });
  });
});
