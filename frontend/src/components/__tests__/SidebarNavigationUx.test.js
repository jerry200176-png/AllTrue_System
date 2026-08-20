import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const appSource = readFileSync(resolve(__dirname, '../../App.vue'), 'utf8');

describe('sidebar navigation UX contract', () => {
  it('uses work-oriented group names and keeps course lookup beside students', () => {
    for (const marker of ['title: \'今日工作\'', 'title: \'教學現場\'', 'key: \'students-courses\'', 'title: \'學生與課程\'', "label: '課程查找'", "title: '設定與資源'"]) {
      expect(appSource).toContain(marker);
    }
  });

  it('exposes active state and accessible names in every navigation renderer', () => {
    expect(appSource).toContain(":aria-current=\"active === item.page ? 'page' : undefined\"");
    expect(appSource).toContain(":aria-label=\"sidebarCollapsed ? item.label : undefined\"");
    expect(appSource).toContain(":aria-current=\"tab.page !== 'more' && active === tab.page ? 'page' : undefined\"");
    expect(appSource).toContain('type="button"');
  });

  it('does not duplicate course management in the teaching group', () => {
    const teachingBlock = appSource.match(/key: 'teaching',[\s\S]*?key: 'students-courses'/)?.[0] || '';
    expect(teachingBlock).not.toContain("page: 'course-mgmt'");
  });
});
