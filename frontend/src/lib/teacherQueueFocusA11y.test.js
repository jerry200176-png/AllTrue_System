import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = readFileSync(new URL('../pages/TeacherHomePage.vue', import.meta.url), 'utf8');

assert.match(source, /href="#teacher-work-queue-title" @click="focusTeacherWorkQueue"/);
assert.match(source, /id="teacher-work-queue-title" tabindex="-1"/);
assert.match(source, /function focusTeacherWorkQueue\(event\)/);
assert.match(source, /event\.preventDefault\(\)/);
assert.match(source, /target\?\.scrollIntoView\(\{ behavior: 'smooth', block: 'start' \}\)/);
assert.match(source, /target\?\.focus\(\{ preventScroll: true \}\)/);

console.log('teacherQueueFocusA11y.test.js: ok');
