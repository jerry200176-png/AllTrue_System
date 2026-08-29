#!/usr/bin/env node
import fs from 'node:fs';
import assert from 'node:assert/strict';

const source = fs.readFileSync(new URL('../pages/BugReportsPage.vue', import.meta.url), 'utf8');
const listBlock = source.slice(source.indexOf('class="bug-list"'), source.indexOf('<!-- Pagination -->'));

assert.match(listBlock, /<button[^>]*v-for="bug in bugs"[^>]*type="button"[^>]*>/);
assert.match(listBlock, /<button[^>]*v-for="bug in bugs"[^>]*:aria-pressed="activeBug\?\.id === bug\.id"[^>]*>/);
assert.match(listBlock, /<button[^>]*v-for="bug in bugs"[^>]*@click="selectBug\(bug\)"[^>]*>/);
assert.match(source, /\.bug-item:focus-visible\s*\{/);
assert.doesNotMatch(listBlock, /<div(?=[^>]*v-for="bug in bugs")[^>]*>/);

console.log('bugReportsListA11y.test.js: all assertions passed');
