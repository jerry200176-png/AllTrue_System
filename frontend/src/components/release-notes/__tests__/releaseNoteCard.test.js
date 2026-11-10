import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import ReleaseNoteCard from '../ReleaseNoteCard.vue';

// #4989: 版本更新 on components/ui. Same content rules as the At* page it replaces.
const note = (o = {}) => ({
  id: 'n1',
  version: '2026.11.11',
  title: '測試公告標題',
  summary: '這是一段白話摘要。',
  date: '2026-11-11',
  importance: 'digest',
  sections: [{ title: '怎麼操作', items: ['第一步', '第二步'] }],
  ...o,
});

describe('ReleaseNoteCard', () => {
  it('shows version, importance label, date, title and summary on a ui Card', () => {
    const w = mount(ReleaseNoteCard, { props: { note: note() } });
    expect(w.attributes('data-slot')).toBe('card');
    expect(w.find('[data-testid="release-note-version"]').text()).toBe('2026.11.11');
    expect(w.find('[data-importance="digest"]').text()).toBe('本週更新');
    expect(w.find('h2').text()).toBe('測試公告標題');
    expect(w.text()).toContain('2026-11-11');
    expect(w.text()).toContain('這是一段白話摘要。');
    expect(w.text()).not.toContain('最近一次更新');
  });

  it('maps every importance to its existing wording', () => {
    const label = (importance) => mount(ReleaseNoteCard, { props: { note: note({ importance }) } }).find('[data-importance]').text();
    expect(label('action_required')).toBe('需要注意');
    expect(label('major')).toBe('重要更新');
    expect(label('digest')).toBe('本週更新');
    expect(mount(ReleaseNoteCard, { props: { note: note({ importance: '' }) } }).find('[data-importance]').exists()).toBe(false);
  });

  it('keeps 操作細節 in a closed native <details> whose <summary> is a 44px+ ui Button', () => {
    const w = mount(ReleaseNoteCard, { props: { note: note() } });
    const details = w.find('details');
    expect(details.exists()).toBe(true);
    expect(details.attributes('open')).toBeUndefined();
    const summary = details.find('summary');
    expect(summary.attributes('data-slot')).toBe('button');
    expect(summary.text()).toBe('查看操作細節');
    expect(summary.classes()).toContain('tw:min-h-12');
    expect(summary.find('svg').attributes('aria-hidden')).toBe('true');
    expect(details.find('h3').text()).toBe('怎麼操作');
    expect(details.findAll('li').map((li) => li.text())).toEqual(['第一步', '第二步']);
  });

  it('falls back to one 你現在可以 section built from note.items', () => {
    const w = mount(ReleaseNoteCard, { props: { note: note({ sections: [], items: ['可以做 A'] }) } });
    expect(w.find('details h3').text()).toBe('你現在可以');
    expect(w.findAll('details li').map((li) => li.text())).toEqual(['可以做 A']);
  });

  it('featured: 最近一次更新 badge, 生效 line only when it differs, and no 操作細節', () => {
    const w = mount(ReleaseNoteCard, { props: { featured: true, note: note({ publishedAt: '2026-11-11', effectiveAt: '2026-11-12' }) } });
    expect(w.attributes('data-testid')).toBe('release-note-featured');
    expect(w.text()).toContain('最近一次更新');
    expect(w.text()).toContain('已於 2026-11-12 生效');
    expect(w.find('details').exists()).toBe(false);
    const same = mount(ReleaseNoteCard, { props: { featured: true, note: note({ publishedAt: '2026-11-11', effectiveAt: '2026-11-11' }) } });
    expect(same.text()).not.toContain('生效');
  });
});
