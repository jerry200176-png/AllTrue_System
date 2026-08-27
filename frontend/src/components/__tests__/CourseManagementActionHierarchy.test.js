import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const pagePath = resolve(__dirname, '../../pages/CourseManagement.vue');
const source = readFileSync(pagePath, 'utf8');
const activeActionsStart = source.indexOf('<td class="cell-actions">');
const activeActionsEnd = source.indexOf('<tr v-if="expandedDates.has(c.id)"', activeActionsStart);
const activeActions = source.slice(activeActionsStart, activeActionsEnd);
const activeMoreMenu = activeActions.slice(activeActions.indexOf('class="action-dropdown"'));

describe('CourseManagement action hierarchy', () => {
  it('keeps core course work visible and groups secondary operations in More', () => {
    expect(activeActions).toContain('course-primary-action');
    expect(activeActions).toContain('@click="navigateToStudentCourse(c)"');
    expect(activeActions).toContain('manual-occurrence-action');
    expect(activeActions).toContain('btn-toggle');
    expect(activeActions).toContain('更多 ▾');
    expect(activeActions).toContain('排課與課堂');
    expect(activeActions).toContain('帳務與合約');
    expect(activeActions).toContain('合約／堂次調整');
    expect(activeActions).not.toContain('btn-invoices');
    expect(activeActions).not.toContain('>+ 補課</button>');
  });

  it('keeps the More menu accessible and preserves existing advanced handlers', () => {
    expect(activeActions).toContain('aria-haspopup="menu"');
    expect(activeActions).toContain('role="menu"');
    expect(activeActions).toContain('role="menuitem"');
    expect(activeActions.match(/openManualSessionModal\(c\)/g)).toHaveLength(2);
    expect(activeMoreMenu).not.toContain('openManualSessionModal(c)');
    expect(activeActions).toContain('@click="openInvoiceModal(c); closeActionMenu()"');
    expect(activeActions).toContain('@click="openContractAdjustmentModal(c); closeActionMenu()"');
    expect(activeActions).toContain('@click="duplicateCourseForTeacher(c); closeActionMenu()"');
  });

  it('does not let an earlier manual-session check overwrite the latest selection', () => {
    expect(source).toContain('let manualSessionCheckVersion = 0;');
    expect(source).toContain('const requestVersion = ++manualSessionCheckVersion;');
    expect(source).toContain('if (requestVersion !== manualSessionCheckVersion) return;');
    expect(source).toContain("import { nextManualSessionDate } from '../lib/manualSessionDate.js';");
    expect(source).toContain('session_date: nextManualSessionDate(course)');
    expect(source).toContain('let quickAddCheckVersion = 0;');
    expect(source).toContain('const requestVersion = ++quickAddCheckVersion;');
    expect(source).toContain('Disable submit during the debounce window');
  });
});
