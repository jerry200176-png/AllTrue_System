#!/usr/bin/env node
import fs from 'node:fs';
import assert from 'node:assert/strict';

const source = fs.readFileSync(new URL('../pages/BugReportsPage.vue', import.meta.url), 'utf8');
const listBlock = source.slice(source.indexOf('class="bug-list"'), source.indexOf('<!-- Pagination -->'));

assert.match(listBlock, /<button[^>]*v-for="bug in bugs"[^>]*type="button"[^>]*>/);
assert.match(listBlock, /<button[^>]*v-for="bug in bugs"[^>]*:aria-current="activeBug\?\.id === bug\.id \? 'true' : undefined"[^>]*>/);
assert.match(listBlock, /<button[^>]*v-for="bug in bugs"[^>]*@click="selectBug\(bug, \$event\)"[^>]*>/);
assert.match(source, /\.bug-item:focus-visible\s*\{/);
assert.doesNotMatch(listBlock, /<div(?=[^>]*v-for="bug in bugs")[^>]*>/);
assert.doesNotMatch(listBlock, /<button[\s\S]*<div/);
assert.match(source, /<h3 ref="detailTitleEl" tabindex="-1">/);
assert.match(source, /const focusDetail = event\?\.detail === 0;/);
assert.match(source, /detailTitleEl\.value\?\.focus\(\{ preventScroll: true \}\)/);

console.log('bugReportsListA11y.test.js: all assertions passed');
