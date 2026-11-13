import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/CourseManagement.vue'), 'utf8');
const groupSource = readFileSync(resolve(__dirname, '../ui/page/CollapsibleGroup.vue'), 'utf8');

describe('CourseManagement disclosure accessibility', () => {
  it('uses native buttons for student group disclosure and the sibling focus action', () => {
    // The toggle is the shared CollapsibleGroup's native button; the page feeds it the state and keeps the old class hook.
    expect(source).toMatch(/<CollapsibleGroup\b[^>]*toggle-class="student-group-toggle"/);
    expect(source).toContain(':expanded="expandedStudentGroups.has(group.key)"');
    expect(source).toContain(':controls="studentGroupPanelId(group.key, studentGroupTab(group.key))"');
    expect(groupSource).toMatch(/<button\b[^>]*type="button"/);
    expect(groupSource).toContain(':aria-expanded="props.expanded"');
    expect(groupSource).toContain(':aria-controls="props.controls"');
    expect(groupSource).toContain(':id="props.toggleId"');
    // The focus action is a sibling of the toggle (actions slot), never inside it.
    expect(groupSource).toMatch(/<\/button>\s*<div v-if="\$slots.actions"/);
    expect(source).toMatch(/<Button\b[^>]*class="focus-btn\b/);
    expect(source).toContain(':aria-label="focusedStudentKey === group.key');
    expect(source).not.toContain('class="student-group-header"\n            role="button"');
  });

  it('connects course and billing tabs to keyboard-focusable tab panels', () => {
    expect(source).toContain(':aria-controls="studentGroupPanelId(group.key, \'courses\')"');
    expect(source).toContain(':aria-controls="studentGroupPanelId(group.key, \'billing\')"');
    expect(source).toContain('role="tabpanel"');
    expect(source).toContain(':aria-labelledby="studentGroupTabId(group.key, \'courses\')"');
    expect(source).toContain(':aria-labelledby="studentGroupTabId(group.key, \'billing\')"');
  });

  it('makes history courses a proper disclosure with a stable controlled region', () => {
    expect(source).toMatch(/<button\b[^>]*class="history-section__toggle(?:\s[^"]*)?"/);
    expect(source).toContain(':aria-expanded="expandedHistoryGroups.has(historySegmentKey(group.key, segment.key))"');
    expect(source).toContain(':aria-controls="historyGroupPanelId(group.key, segment.key)"');
    expect(source).toContain(':id="historyGroupPanelId(group.key, segment.key)"');
    expect(source).toContain('const historyGroupPanelId = (key, segmentKey)');
  });

  it('supports keyboard navigation and focus restoration for More menus without changing menu actions', () => {
    expect(source).toContain('@keydown="handleActionMenuKeydown(hc.id, $event)"');
    expect(source).toContain("['ArrowDown', 'ArrowUp', 'Home', 'End', 'Escape']");
    expect(source).toContain("closeActionMenu({ restoreFocus: true })");
    expect(source).toContain("querySelectorAll('[role=\"menuitem\"]:not(:disabled)')");
    expect(source).toContain('actionMenuTriggers.get(courseId)?.focus()');
  });
});
