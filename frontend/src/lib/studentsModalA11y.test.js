import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = readFileSync(new URL('../pages/StudentsList.vue', import.meta.url), 'utf8');
const dialogs = [
  ['student-modal-title', 'showStudentModal'],
  ['course-modal-title', 'showCourseModal && editingCourseId'],
  ['sessions-modal-title', 'showSessionsModal'],
  ['grade-promotion-modal-title', 'showGradePromotion'],
];

for (const [titleId, condition] of dialogs) {
  assert.match(source, new RegExp(`v-if="${condition}"[\\s\\S]*role="dialog"[\\s\\S]*aria-modal="true"[\\s\\S]*aria-labelledby="${titleId}"`));
  assert.match(source, new RegExp(`<h3 id="${titleId}"`));
}

// Identity dialog moved to its own component (#4659); the page must still mount it.
const identitySource = readFileSync(new URL('../components/students/IdentityLinkModal.vue', import.meta.url), 'utf8');
assert.match(identitySource, /v-if="show"[\s\S]*role="dialog"[\s\S]*aria-modal="true"[\s\S]*aria-labelledby="identity-modal-title"/);
assert.match(identitySource, /<h3 id="identity-modal-title"/);
assert.match(source, /<IdentityLinkModal[\s\S]*:show="showIdentityModal"/);

console.log('studentsModalA11y.test.js: ok');
