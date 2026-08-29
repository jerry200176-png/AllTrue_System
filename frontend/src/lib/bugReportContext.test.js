#!/usr/bin/env node
import assert from 'node:assert/strict';
import { parseBugReportClientInfo } from './bugReportContext.js';

assert.deepEqual(parseBugReportClientInfo(JSON.stringify({
  occurrenceAt: ' 2026-08-29T14:30 ',
  relatedReference: '學生 271／課堂 32570',
  screenSize: '1280x720',
  timeZone: 'Asia/Taipei',
  userAgent: 'not rendered by the triage summary',
})), {
  occurrenceAt: '2026-08-29T14:30',
  relatedReference: '學生 271／課堂 32570',
  screenSize: '1280x720',
  timeZone: 'Asia/Taipei',
});
assert.equal(parseBugReportClientInfo('not-json'), null);
assert.equal(parseBugReportClientInfo(JSON.stringify({ userAgent: 'legacy-only' })), null);
assert.equal(parseBugReportClientInfo(JSON.stringify({ relatedReference: 'x'.repeat(500) })).relatedReference.length, 300);

console.log('bugReportContext.test.js: all assertions passed');
