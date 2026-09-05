import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { isCurrentListRequest, shouldShowInitialListSkeleton } from './listRefreshState.js';

const source = readFileSync(new URL('../pages/BugReportsPage.vue', import.meta.url), 'utf8');

assert.match(source, /class="bugs-page-tabs" role="tablist" aria-label="Bug 回報與家長回饋"/);
assert.match(source, /id="bugs-tab"[\s\S]*role="tab"[\s\S]*aria-controls="bugs-panel"[\s\S]*:aria-selected="pageTab === 'bugs'"/);
assert.match(source, /id="feedback-tab"[\s\S]*role="tab"[\s\S]*aria-controls="feedback-panel"[\s\S]*:aria-selected="pageTab === 'feedback'"/);
assert.match(source, /id="feedback-panel"[\s\S]*role="tabpanel"[\s\S]*aria-labelledby="feedback-tab"/);
assert.match(source, /id="bugs-panel"[\s\S]*role="tabpanel"[\s\S]*:aria-labelledby="isSuperAdmin \? 'bugs-tab' : undefined"/);
assert.match(source, /class="quick-tabs" role="group" aria-label="Bug 狀態篩選"/);
assert.match(source, /type="button" class="quick-tab" :aria-pressed="quickFilter === 'pending'"/);
assert.match(source, /focusBugId: \{ type: \[Number, String\], default: null \}/);
assert.match(source, /lastFocusedBugId/);
assert.equal(isCurrentListRequest(2, 2), true);
assert.equal(isCurrentListRequest(1, 2), false);
assert.equal(shouldShowInitialListSkeleton(true, []), true);
assert.equal(shouldShowInitialListSkeleton(true, [{ id: 1 }]), false);

console.log('bugReportsPageA11y.test.js: ok');
