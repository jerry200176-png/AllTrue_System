import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const app = readFileSync(new URL('../App.vue', import.meta.url), 'utf8');
assert.match(app, /^const asyncPage = \(loader\) => defineAsyncPage\(trackLoader\(loader\)\);$/m);
assert.doesNotMatch(app, /^const \w+\s*= defineAsync(?:Component|Page)\(/m, 'page chunks must use asyncPage()');
assert.match(app, /^const BranchHealthBoard\s*= asyncPage\(/m);

const cm = readFileSync(new URL('../pages/CourseManagement.vue', import.meta.url), 'utf8');
assert.match(cm, /params\.set\('class_type', filters\.value\.class_type\)/);
assert.doesNotMatch(cm, /typeFilter/, 'fetch-all-pages workaround removed; backend filters class_type');
assert.match(cm, /const loadStudents = async[\s\S]{0,400}fetchAllPages\(`\/api\/v1\/students\?/, '新增課程 scheduler needs every student page, not the first 500');

const bugs = readFileSync(new URL('../pages/BugReportsPage.vue', import.meta.url), 'utf8');
assert.match(bugs, /listDisplay\(bug\)\.title/);
console.log('asyncPageAndCourseTypeFilter.test.js: all assertions passed');
