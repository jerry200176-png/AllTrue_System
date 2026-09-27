#!/usr/bin/env node
import assert from 'node:assert/strict';
import {
  parseBugReportClientInfo,
  statusLogDisplayNote,
  stripBugStatusMachineMarkers,
} from './bugReportContext.js';

assert.deepEqual(parseBugReportClientInfo(JSON.stringify({
  occurrenceAt: ' 2026-08-29T14:30 ',
  relatedReference: '學生 271／課堂 32570',
  screenSize: '1280x720',
  timeZone: 'Asia/Taipei',
  feedbackType: ' suggestion ',
  userAgent: 'not rendered by the triage summary',
})), {
  occurrenceAt: '2026-08-29T14:30',
  relatedReference: '學生 271／課堂 32570',
  screenSize: '1280x720',
  timeZone: 'Asia/Taipei',
  feedbackType: 'suggestion',
});
assert.equal(parseBugReportClientInfo('not-json'), null);
assert.equal(parseBugReportClientInfo(JSON.stringify({ userAgent: 'legacy-only' })), null);
assert.equal(parseBugReportClientInfo(JSON.stringify({ relatedReference: 'x'.repeat(500) })).relatedReference.length, 300);

assert.equal(
  stripBugStatusMachineMarkers('[product_disposition]{"kind":"bug"}\n分診完成'),
  '分診完成',
);
assert.equal(
  stripBugStatusMachineMarkers('[resolution_evidence]{"production_revision":"abc1234"}'),
  '',
);
assert.equal(statusLogDisplayNote({ note_display: '人類文字', note: '[product_disposition]{}' }), '人類文字');
assert.equal(statusLogDisplayNote({ note_display: '', note: '[product_disposition]{"kind":"bug"}' }), '');
assert.equal(
  statusLogDisplayNote({ note: '[product_disposition]{"kind":"bug"}\n歷史純文字' }),
  '歷史純文字',
);
assert.equal(statusLogDisplayNote({ note: '[resolution_evidence]{"production_revision":"abc"}' }), '');

console.log('bugReportContext.test.js: all assertions passed');
