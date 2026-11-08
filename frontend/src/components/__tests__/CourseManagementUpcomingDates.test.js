import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const pageSource = readFileSync(resolve(__dirname, '../../pages/CourseManagement.vue'), 'utf8');
const composableSource = readFileSync(resolve(__dirname, '../../composables/course-management/useCourseSessionsDisplay.js'), 'utf8');

describe('CourseManagement upcoming lesson preview (#321)', () => {
  it('uses the existing effective session view model and stable keys', () => {
    expect(pageSource).toContain('aria-label="下一堂"');
    expect(pageSource).toContain('upcomingSessionPreview(c, { todayYmd, limit: 1 })');
    expect(pageSource).toContain('formatSessionChipDate(unit)');
    expect(pageSource).toContain(':key="sessionRowKey(unit)"');
    expect(composableSource).toContain('primarySessionUnits(course)');
  });

  it('covers loading, retryable error, empty and overflow states', () => {
    expect(pageSource).toContain('載入中…');
    expect(pageSource).toContain('暫時無法載入。');
    expect(pageSource).toContain("'暫無下一堂' : '未排定'");
    expect(pageSource).toContain('重新載入');
  });

  it('keeps the four labeled contract facts plus the existing action region', () => {
    expect(pageSource).toContain('管理課程');
    for (const label of ['科目・老師', '下一堂', '堂數', '繳費']) expect(pageSource).toContain(`<span class="contract-field-label">${label}</span>`);
    expect(pageSource).not.toContain('<th>時段</th>');
    expect(pageSource).not.toContain('fetch(`/api/v1/student-classes/${c.id}/sessions`');
    expect(pageSource).not.toContain('fetch(`/api/v1/student-classes/${c.id}/session');
  });

  it('keeps predictable fact/action order and warnings in the primary contract region', () => {
    const a = pageSource.indexOf('<div class="course-contract-list"');
    const list = pageSource.slice(a, pageSource.indexOf('History courses collapsible section', a));
    const row = list.slice(list.indexOf('<div :class="[\'course-row\''), list.indexOf('<div v-if="!courseManagerEnabled && expandedDates'));
    const cells = [...row.matchAll(/<div[^>]*(?:data-col="(\w+)"|class="(cell-actions)")/g)].map((m) => m[1] || 'actions');
    expect(cells).toEqual(['subject', 'next', 'remaining', 'payment', 'actions']);
    const subjectCell = row.slice(row.indexOf('data-col="subject"'), row.indexOf('data-col="next"'));
    for (const w of ['rowWarningSummary(group, c)', 'role="alert"', '查看待核對回報']) expect(subjectCell).toContain(w);
    expect(row.slice(row.indexOf('data-col="payment"'))).toContain('payment-status-badge');
    const secondaryFacts = row.slice(row.indexOf('data-col="next"'));
    expect(secondaryFacts).not.toContain('role="alert"');
    expect(secondaryFacts).not.toContain('rowWarningSummary');
  });
});
