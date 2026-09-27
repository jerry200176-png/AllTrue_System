import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const frontendRoot = resolve(__dirname, '../..');
const page = readFileSync(resolve(frontendRoot, 'pages/DuplicateSessionReviewPage.vue'), 'utf8');
const reviewStore = readFileSync(resolve(frontendRoot, 'composables/useDuplicateReview.js'), 'utf8');
const reviewApi = readFileSync(resolve(frontendRoot, 'lib/duplicateReviewApi.js'), 'utf8');

describe('DuplicateSessionReviewPage presentation contract', () => {
  it('uses the shared, accessible states without adding a request path', () => {
    expect(page).toContain("import AtSkeleton from '../components/design-system/AtSkeleton.vue';");
    expect(page).toContain("import AtInlineAlert from '../components/design-system/AtInlineAlert.vue';");
    expect(page).toContain("import AtEmpty from '../components/design-system/AtEmpty.vue';");
    expect(page).toContain('<AtSkeleton v-if="loading" :rows="6" />');
    expect(page).toContain('title="無法載入重複課程審核"');
    expect(page).toContain('title="沒有待審核的重複課程時段"');
    expect(page).toContain('min-height: 44px;');
    expect(page).not.toContain('fetch(');
    expect(page).not.toContain('/api/v1/admin/duplicate-sessions');
  });

  it('keeps the existing decision payload and execution endpoint unchanged', () => {
    expect(reviewStore).toContain('const result = await patchP2ReviewGroup(g.id, {');
    expect(reviewStore).toContain('keep_student_class_id: d.keepScId,');
    expect(reviewStore).toContain('reason: d.reason || undefined,');
    expect(reviewApi).toContain("method: 'PATCH'");
    expect(reviewApi).toContain("/admin/duplicate-sessions/p2-review/${encodeURIComponent(groupId)}");
    expect(reviewApi).toContain('body: JSON.stringify({ keep_student_class_id, reason })');
  });
});
