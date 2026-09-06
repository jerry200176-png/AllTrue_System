import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { getNavigationGroups } from '../../lib/navigationRegistry';

const appSource = readFileSync(resolve(__dirname, '../../App.vue'), 'utf8');
const registrySource = readFileSync(resolve(__dirname, '../../lib/navigationRegistry.js'), 'utf8');

describe('sidebar navigation UX contract', () => {
  it('uses work-oriented group names and keeps course lookup beside students', () => {
    for (const marker of ["title: '今日工作'", "title: '教學現場'", "key: 'students-courses'", "title: '學生與課程'", "label: '課程查找'", "title: '設定與資源'"]) {
      expect(registrySource).toContain(marker);
    }
    expect(getNavigationGroups('director').flatMap(group => group.items.map(item => item.page)))
      .toContain('course-mgmt');
  });

  it('exposes active state and accessible names in every navigation renderer', () => {
    expect(appSource).toContain(":aria-current=\"active === item.page ? 'page' : undefined\"");
    expect(appSource).toContain(":aria-label=\"sidebarCollapsed ? item.label : undefined\"");
    expect(appSource).toContain(":aria-current=\"tab.page !== 'more' && active === tab.page ? 'page' : undefined\"");
    expect(appSource).toContain(':aria-expanded="String(isSidebarGroupOpen(group))"');
    expect(appSource).toContain('class="sidebar-more-trigger"');
    expect(appSource).toContain('id="sidebar-more-trigger"');
    expect(appSource).toContain('aria-controls="sidebar-more-panel"');
    expect(appSource).toContain('class="sidebar-more-panel"');
    expect(appSource).toContain('tabindex="-1"');
    expect(appSource).toContain('id="mobile-more-sheet"');
    expect(appSource).toContain('aria-labelledby="mobile-more-title"');
    expect(appSource).toContain(':aria-expanded="tab.page === \'more\' ? String(showMoreMenu) : undefined"');
    expect(appSource).toContain('type="button"');
  });

  it('gives both More surfaces a predictable open, dismiss, and focus-return contract', () => {
    expect(appSource).toContain('function toggleSidebarMore()');
    expect(appSource).toContain('function closeSidebarMore(restoreFocus = true)');
    expect(appSource).toContain('function toggleMoreMenu()');
    expect(appSource).toContain('function closeMoreMenu(restoreFocus = true)');
    expect(appSource).toContain("@keydown.esc.prevent=\"closeSidebarMore()\"");
    expect(appSource).toContain("@keydown.esc.prevent=\"closeMoreMenu()\"");
    expect(appSource).toContain("document.querySelector('#sidebar-more-trigger')?.focus()");
    expect(appSource).toContain("document.querySelector('#mobile-more-trigger')?.focus()");
  });

  it('keeps personal display tools out of the navigation rail', () => {
    expect(appSource).not.toContain('class="shortcut-hint"');
    expect(appSource).not.toContain('class="theme-switcher"');
    expect(appSource).toContain('class="account-menu-tools"');
    expect(appSource).toContain('class="account-menu-shortcuts"');
    expect(appSource).toContain('material-symbols-outlined theme-btn-icon');
  });

  it('does not duplicate course management in the teaching group', () => {
    const teachingGroup = getNavigationGroups('director').find(group => group.key === 'teaching');
    expect(teachingGroup.items.map(item => item.page)).not.toContain('course-mgmt');
  });

  it('supports progressive search and keyboard navigation in sidebar more panel', () => {
    expect(appSource).toContain('class="sidebar-more-search-input"');
    expect(appSource).toContain('sidebarMoreSearchQuery');
    expect(appSource).toContain('sidebarMoreFilteredGroups');
    expect(appSource).toContain('onSidebarMoreSearchEnter');
    expect(appSource).toContain('handleGlobalKeydown');
    expect(appSource).toContain("window.scrollTo({ top: 0, behavior: 'instant' })");
  });
});
