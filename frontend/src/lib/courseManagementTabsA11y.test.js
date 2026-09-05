import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = readFileSync(new URL('../pages/CourseManagement.vue', import.meta.url), 'utf8');

assert.match(source, /role="tablist"[\s\S]*@keydown="handleStudentGroupTabKeydown\(group, \$event\)"/);
assert.match(source, /data-testid="student-tab-courses"[\s\S]*:tabindex="studentGroupTab\(group.key\) === 'courses' \? 0 : -1"/);
assert.match(source, /data-testid="student-tab-billing"[\s\S]*:tabindex="studentGroupTab\(group.key\) === 'billing' \? 0 : -1"/);
assert.match(source, /const handleStudentGroupTabKeydown = async \(group, event\) =>/);
assert.match(source, /event\.key === 'ArrowRight' \|\| event\.key === 'ArrowDown'/);
assert.match(source, /event\.key === 'ArrowLeft' \|\| event\.key === 'ArrowUp'/);
assert.match(source, /event\.key === 'Home'/);
assert.match(source, /event\.key === 'End'/);
assert.match(source, /querySelector\(`\[data-testid="student-tab-\$\{nextTab\}"\]`\)\?\.focus\(\)/);
assert.match(source, /let courseLoadRequestId = 0;/);
assert.match(source, /const isCurrent = \(\) => isCurrentListRequest\(requestId, courseLoadRequestId\);/);

console.log('courseManagementTabsA11y.test.js: ok');
