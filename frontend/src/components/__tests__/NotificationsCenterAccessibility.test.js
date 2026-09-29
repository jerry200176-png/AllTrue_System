import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(here, '../../pages/NotificationsCenter.vue'), 'utf8');

describe('notifications center tab accessibility contract', () => {
  it('connects the inbox tabs to their active panel and exposes one tab stop', () => {
    expect(source).toContain('role="tablist" aria-label="收件匣分類" aria-orientation="horizontal"');
    expect(source).toContain(':id="tab.id"');
    for (const tab of ['cases', 'ops']) {
      expect(source).toContain(`id: 'notifications-tab-${tab}'`);
      expect(source).toContain(`panelId: 'notifications-panel-${tab}'`);
      expect(source).toContain(':aria-controls="tab.panelId"');
    }
    expect(source).toContain(':tabindex="typeFilter === tab.value ? 0 : -1"');
    expect(source).toContain(':id="activeNotificationPanelId"');
    expect(source).toContain(':aria-labelledby="activeNotificationTabId"');
    expect(source).toContain('role="tabpanel"');
  });

  it('supports standard horizontal tab navigation without changing the data contract', () => {
    expect(source).toContain('@keydown="onTypeTabKeydown($event, tab.value)"');
    expect(source).toContain("['ArrowRight', 'ArrowLeft', 'Home', 'End']");
    expect(source).toContain('event.preventDefault();');
    expect(source).toContain('onTabClick(nextTab.value);');
    expect(source).toContain('document.getElementById(nextTab.id)?.focus()');
  });
});

describe('notifications center load sequence (perf)', () => {
  const mount = source.slice(source.indexOf('onMounted(async () => {'));
  it('paints the list before the background sync instead of awaiting the sync first', () => {
    const load = mount.indexOf('await loadNotifications(1);');
    const sync = mount.indexOf('void syncNotifications(false);');
    expect(load).toBeGreaterThan(-1);
    expect(sync).toBeGreaterThan(load);
    expect(mount).not.toContain('await syncNotifications');
  });

  it('runs the count request in parallel with the list GETs once the lane is resolved', () => {
    expect(source).toContain('if (needLaneFromCount) await countP;');
    expect(source).toContain('Promise.allSettled([caseP, notifP, countP])');
  });
});
