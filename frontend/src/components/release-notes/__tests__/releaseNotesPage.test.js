import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// #4989: 版本更新 page on components/ui. Notes are mocked so the test does not follow docs/staff-updates.
const note = (n) => ({ id: `n${n}`, version: `2026.11.${String(30 - n).padStart(2, '0')}`, title: `公告 ${n}`, summary: `摘要 ${n}`, date: `2026-11-${String(30 - n).padStart(2, '0')}`, importance: 'digest', items: [`操作 ${n}`] });
const byRole = { director: Array.from({ length: 7 }, (_, i) => note(i + 1)), teacher: [note(1), note(2)], student: [] };
vi.mock('../../../lib/releaseNotes', () => ({ notesForRole: (role) => byRole[role] || [] }));

const { default: ReleaseNotesPage } = await import('../../../pages/ReleaseNotesPage.vue');
const page = (userRole) => mount(ReleaseNotesPage, { props: { userRole } });

describe('ReleaseNotesPage', () => {
  it('header keeps the title, the meta line and the CHANGELOG link (new tab, 44px+)', () => {
    const w = page('director');
    expect(w.find('h1').text()).toBe('版本更新');
    expect(w.text()).toContain('最新版本 2026.11.29');
    expect(w.text()).toContain('7 則公告');
    const link = w.find('a[data-slot="button"]');
    expect(link.text()).toBe('查看完整 CHANGELOG');
    expect(link.attributes()).toMatchObject({ target: '_blank', rel: 'noopener noreferrer' });
    expect(link.attributes('href')).toMatch(/docs\/CHANGELOG\.md$/);
    expect(link.classes()).toContain('tw:h-12');
  });

  it('newest note is the one featured card; the next three are listed; the hint counts both', () => {
    const w = page('director');
    expect(w.findAll('[data-testid="release-note-featured"]')).toHaveLength(1);
    expect(w.find('[data-testid="release-note-featured"] h2').text()).toBe('公告 1');
    expect(w.findAll('[data-testid="release-note"] h2').map((h) => h.text())).toEqual(['公告 2', '公告 3', '公告 4']);
    expect(w.text()).toContain('顯示最近 4 則；點擊查看其他公告。');
  });

  it('older notes sit behind 查看更早公告 and are built on first open, then kept', async () => {
    const w = page('director');
    const older = w.find('[data-testid="release-notes-older"]');
    expect(older.find('summary').text()).toBe('查看更早公告（3 則）');
    expect(older.findAll('[data-testid="release-note"]')).toHaveLength(0);
    older.element.open = true;
    await older.trigger('toggle');
    expect(older.findAll('[data-testid="release-note"] h2').map((h) => h.text())).toEqual(['公告 5', '公告 6', '公告 7']);
    older.element.open = false;
    await older.trigger('toggle');
    expect(older.findAll('[data-testid="release-note"]')).toHaveLength(3);
  });

  it('four notes or fewer: no hint and no older section', () => {
    const w = page('teacher');
    expect(w.find('[data-testid="release-notes-older"]').exists()).toBe(false);
    expect(w.text()).not.toContain('點擊查看其他公告');
  });

  it('no notes for the role: the existing empty wording, and nothing else', () => {
    const w = page('student');
    const empty = w.find('[data-testid="release-notes-empty"]');
    expect(empty.find('h2').text()).toBe('目前尚無可顯示的更新內容');
    expect(empty.text()).toContain('有新的核准公告時，會在這裡顯示。');
    expect(w.text()).toContain('0 則公告');
    expect(w.text()).not.toContain('最新版本');
    expect(w.find('[data-testid="release-note-featured"]').exists()).toBe(false);
  });
});
