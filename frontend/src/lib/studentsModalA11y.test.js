import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = readFileSync(new URL('../pages/StudentsList.vue', import.meta.url), 'utf8');
const dialogs = [
  ['student-modal-title', 'showStudentModal'],
  ['course-modal-title', 'showCourseModal && editingCourseId'],
  ['invoice-modal-title', 'showInvoiceModal'],
  ['sessions-modal-title', 'showSessionsModal'],
  ['grade-promotion-modal-title', 'showGradePromotion'],
  ['identity-modal-title', 'showIdentityModal'],
];

for (const [titleId, condition] of dialogs) {
  assert.match(source, new RegExp(`v-if="${condition}"[\\s\\S]*role="dialog"[\\s\\S]*aria-modal="true"[\\s\\S]*aria-labelledby="${titleId}"`));
  assert.match(source, new RegExp(`<h3 id="${titleId}"`));
}

console.log('studentsModalA11y.test.js: ok');
