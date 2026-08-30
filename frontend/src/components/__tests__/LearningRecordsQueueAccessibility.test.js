import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/LearningRecordsPage.vue'), 'utf8');

describe('LearningRecords review queue accessibility', () => {
  it('exposes director review statuses as a labelled tablist', () => {
    expect(source).toContain('role="tablist" aria-label="主任評量審核佇列"');
    expect(source).toContain('id="lr-review-tab-pending"');
    expect(source).toContain('id="lr-review-tab-changes_requested"');
    expect(source).toContain('id="lr-review-tab-approved"');
    expect(source).toContain('id="lr-review-tab-rejected"');
    expect(source).toContain('id="lr-review-tab-all"');
    expect(source).toContain('aria-controls="lr-review-panel"');
  });

  it('exposes teacher filter statuses as a labelled tablist', () => {
    expect(source).toContain('role="tablist" aria-label="老師評量審核狀態"');
    expect(source).toContain('id="lr-teacher-tab-all"');
    expect(source).toContain('id="lr-teacher-tab-pending"');
    expect(source).toContain('id="lr-teacher-tab-changes_requested"');
    expect(source).toContain('id="lr-teacher-tab-approved"');
  });

  it('keeps the shared review list in one labelled tabpanel', () => {
    expect(source).toContain(":id=\"pageMode === 'records' ? 'lr-review-panel' : undefined\"");
    expect(source).toContain(":role=\"pageMode === 'records' ? 'tabpanel' : undefined\"");
    expect(source).toContain(":tabindex=\"pageMode === 'records' ? 0 : undefined\"");
    expect(source).toContain(":aria-labelledby=\"pageMode === 'records' ? (isDirectorRole ? `lr-review-tab-${reviewTab}` : `lr-teacher-tab-${teacherFilterTab}`) : undefined\"");
  });

  it('keeps filter clearing separate from the expand/collapse control', () => {
    const headerStart = source.indexOf('<div class="lr-filters-header">');
    const headerEnd = source.indexOf('<div v-show="showAdvancedFilters"', headerStart);
    const header = source.slice(headerStart, headerEnd);
    const clearControl = header.match(/<button[\s\S]*?class="lr-filters-clear-link"[\s\S]*?>/);

    expect(headerStart).toBeGreaterThanOrEqual(0);
    expect(headerEnd).toBeGreaterThan(headerStart);
    expect(header).toContain('class="lr-filters-header-toggle"');
    expect(header).toContain('class="lr-filters-chevron-toggle"');
    expect(clearControl).not.toBeNull();
    expect(clearControl[0]).toContain('type="button"');
    expect(clearControl[0]).not.toContain('role="button"');
    expect(clearControl[0]).not.toContain('tabindex=');
  });

  it('exposes every quick filter chip as a real toggle button', () => {
    const filterButtons = [...source.matchAll(/<button[\s\S]*?>/g)]
      .map((match) => match[0])
      .filter((openingTag) => openingTag.includes('lr-feedback-filter-chip'));

    expect(filterButtons.length).toBeGreaterThan(0);
    for (const openingTag of filterButtons) {
      expect(openingTag).toContain('type="button"');
      expect(openingTag).toContain(':aria-pressed=');
    }
  });

  it('makes parent-feedback preview chips keyboard-operable disclosures', () => {
    const feedbackButtons = [...source.matchAll(/<button[^>]*:class="\['lr-parent-feedback-chip'[^>]*>/g)]
      .map((match) => match[0]);

    expect(feedbackButtons).toHaveLength(2);
    for (const openingTag of feedbackButtons) {
      expect(openingTag).toContain('type="button"');
      expect(openingTag).toContain(':aria-expanded="feedbackPreviewOpen.has(record.id) ? \'true\' : \'false\'"');
      expect(openingTag).toContain(':aria-label=');
      expect(openingTag).toContain('@click.stop="toggleFeedbackPreview(record)"');
    }
    expect(source).not.toMatch(/<span[^>]*lr-parent-feedback-chip[^>]*>/);
  });

  it('names batch-selection checkboxes with review context', () => {
    const rowCheckboxes = [...source.matchAll(/<input[\s\S]*?type="checkbox"[\s\S]*?>/g)]
      .map((match) => match[0])
      .filter((openingTag) => openingTag.includes('toggleRecordSelection(record.id)'));

    expect(rowCheckboxes).toHaveLength(2);
    for (const openingTag of rowCheckboxes) {
      expect(openingTag).toContain(':aria-label="\'選取\' + (record.student_name || \'未命名學生\')');
      expect(openingTag).toContain('record.SessionDate');
      expect(openingTag).toContain('record.Subject || record.student_class_label || \'未分類\'');
    }

    expect(source).toContain(':aria-label="allSelected ? \'取消全選本頁評量\' : \'全選本頁評量\'"');
  });
});
