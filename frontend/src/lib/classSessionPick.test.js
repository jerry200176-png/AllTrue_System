import assert from 'node:assert/strict';
import { pickBestSessionRow, resolveSessionIdForSubstitute } from './classSessionPick.js';

const rows = [
  { id: 9421, session_date: '2026-05-17', start_time: '10:00', status: 'attended' },
  { id: 9427, session_date: '2026-05-17', start_time: '12:30', status: 'scheduled' },
];

assert.equal(
  resolveSessionIdForSubstitute(rows, '2026-05-17', '12:30'),
  9427,
  'substitute must target the cell start_time, not the attended row'
);

assert.equal(
  resolveSessionIdForSubstitute(rows, '2026-05-17', ''),
  9421,
  'without start hint, attended row still wins'
);

assert.equal(
  resolveSessionIdForSubstitute(
    [
      { id: 1, session_date: '2026-05-17', start_time: '12:30:00', status: 'scheduled' },
      { id: 2, session_date: '2026-05-17', start_time: '10:00:00', status: 'attended' },
    ],
    '2026-05-17',
    '12:30'
  ),
  1,
  'HH:mm:ss start times normalize correctly'
);

assert.equal(
  pickBestSessionRow([
    { id: 10, status: 'scheduled' },
    { id: 20, status: 'attended' },
  ]).id,
  20,
  'pickBestSessionRow prefers attended when no time hint'
);
