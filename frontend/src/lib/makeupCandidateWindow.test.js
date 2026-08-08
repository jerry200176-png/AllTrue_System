import assert from 'node:assert/strict';
import { getMakeupCandidateWindow } from './makeupCandidateWindow.js';

assert.deepEqual(
  getMakeupCandidateWindow({ class_session: { date: '2026-08-20' } }, new Date(2026, 7, 8), 14),
  { sourceDate: '2026-08-20', startDate: '2026-08-21', endDate: '2026-09-03' },
);

assert.deepEqual(
  getMakeupCandidateWindow({ class_session: { date: '2026-08-01' } }, new Date(2026, 7, 8), 14),
  { sourceDate: '2026-08-01', startDate: '2026-08-09', endDate: '2026-08-22' },
);
