import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/CourseManagement.vue'), 'utf8');

describe('CourseManagement disclosure accessibility', () => {
  it('uses native buttons for student group disclosure and the sibling focus action', () => {
    expect(source).toContain('class="student-group-toggle"');
    expect(source).toContain(':aria-expanded="expandedStudentGroups.has(group.key)"');
    expect(source).toContain(':aria-controls="studentGroupPanelId(group.key, studentGroupTab(group.key))"');
    expect(source).toContain('class="focus-btn"');
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
    expect(source).toContain('class="history-section__toggle"');
    expect(source).toContain(':aria-expanded="expandedHistoryGroups.has(group.key)"');
    expect(source).toContain(':aria-controls="historyGroupPanelId(group.key)"');
    expect(source).toContain(':id="historyGroupPanelId(group.key)"');
    expect(source).toContain('const historyGroupPanelId = (key)');
  });
});
