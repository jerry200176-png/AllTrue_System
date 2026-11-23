import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = readFileSync(new URL('../pages/StudentsList.vue', import.meta.url), 'utf8');
const dialogs = [
  ['course-modal-title', 'courseEditorOpen'],
  ['sessions-modal-title', 'showSessionsModal && studentsPageActive'],
  ['grade-promotion-modal-title', 'showGradePromotion && studentsPageActive'],
];

for (const [titleId, condition] of dialogs) {
  const [, afterOpen] = source.split(`<Dialog :open="${condition}"`);
  assert.ok(afterOpen, `Missing dialog for ${titleId}`);
  const [dialog] = afterOpen.split('</Dialog>');
  assert.match(dialog, /<DialogContent\b/);
  assert.match(dialog, new RegExp(`<DialogTitle[^>]*><span id="${titleId}">`));
}

assert.match(source, /const studentEditorOpen = computed\(\(\) => showStudentModal\.value && studentsPageActive\.value\);/);
assert.match(source, /<Dialog :open="studentEditorOpen"/);
assert.match(source, /<DialogContent\s+v-if="courseEditorOpen"/);
assert.match(source, /<DialogContent\s+v-if="studentEditorOpen"/);
assert.match(source, /<DialogTitle[^>]*><span id="student-modal-title">/);

// Identity dialog moved to its own component (#4659); the page must still mount it.
const identitySource = readFileSync(new URL('../components/students/IdentityLinkModal.vue', import.meta.url), 'utf8');
assert.match(identitySource, /v-if="show"[\s\S]*role="dialog"[\s\S]*aria-modal="true"[\s\S]*aria-labelledby="identity-modal-title"/);
assert.match(identitySource, /<h3 id="identity-modal-title"/);
assert.match(source, /<IdentityLinkModal[\s\S]*:show="showIdentityModal"/);

console.log('studentsModalA11y.test.js: ok');
