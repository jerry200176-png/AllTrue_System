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
});
