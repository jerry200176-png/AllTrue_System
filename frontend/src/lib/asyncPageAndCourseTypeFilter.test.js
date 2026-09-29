import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const app = readFileSync(new URL('../App.vue', import.meta.url), 'utf8');
assert.match(app, /loadingComponent: AtSkeleton, delay: 150/);
assert.doesNotMatch(app, /^const \w+\s*= defineAsyncComponent\(/m, 'page chunks must use asyncPage()');
assert.match(app, /^const BranchHealthBoard\s*= asyncPage\(/m);

const cm = readFileSync(new URL('../pages/CourseManagement.vue', import.meta.url), 'utf8');
assert.match(cm, /\(c\.class_type \?\? c\.ClassType \?\? 'one_on_one'\) === typeFilter/, 'class_type filter must be applied client-side (backend ignores it)');

const bugs = readFileSync(new URL('../pages/BugReportsPage.vue', import.meta.url), 'utf8');
assert.match(bugs, /listDisplay\(bug\)\.title/);
console.log('asyncPageAndCourseTypeFilter.test.js: all assertions passed');
