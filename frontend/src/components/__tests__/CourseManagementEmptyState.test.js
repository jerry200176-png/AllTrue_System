import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

// Phase B first slice (docs/plans/2026-08-20-course-ia-consolidation.md, GitHub #1922):
// CourseManagement.vue is a read-only triage lens, not a second CRUD surface.
// This asserts the header "新增課程" button/modal-launcher is gone and the
// empty state only offers a real deep-link to 學生管理 (App.vue 'navigate' tab-switch
// pattern), not a contradictory local "quick add" affordance.
const __dirname = dirname(fileURLToPath(import.meta.url));
const pagePath = resolve(__dirname, '../../pages/CourseManagement.vue');
const source = readFileSync(pagePath, 'utf8');

describe('CourseManagement read-only lens (Phase B first slice)', () => {
  it('has no header "新增課程" button launching the backfill scheduler', () => {
    expect(source).not.toMatch(/btn-accent"\s*@click="openBackfillModal"/);
    expect(source).not.toContain('@click="openBackfillModal"');
    expect(source).not.toContain('function openBackfillModal()');
  });

  it('empty state only points to 學生管理 via a real deep-link, no local add action', () => {
    expect(source).toContain('目前尚無課程資料');
    expect(source).toContain('請在「學生管理」為學生建立課程。');
    expect(source).not.toContain('或使用上方「新增課程」快速建立課程');
    expect(source).toContain('data-testid="empty-state-goto-students"');
    expect(source).toContain("@click=\"emit('navigate', 'students')\"");
  });

  it('deep-link reuses the existing App.vue navigate/tab-switch mechanism, not a new router', () => {
    // 'navigate' is the same emit CourseManagement already uses for 管理科目 (subject-settings),
    // handled by App.vue's onNavigateFromCourseManagement -> active.value = payload.
    expect(source).toContain("const emit = defineEmits(['clear-initial-teacher', 'navigate']);");
    expect(source).toContain("emit('navigate', 'subject-settings')");
  });
});
