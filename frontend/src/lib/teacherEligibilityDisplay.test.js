import assert from 'node:assert/strict';
import { eventKindHint, eventKindLabel, eventSubtitle } from './teacherEligibilityDisplay.js';

assert.match(eventKindHint('holiday'), /全分校/);
assert.equal(eventKindHint('holiday').includes('請假'), true);
assert.match(eventKindHint('leave'), /老師/);
assert.equal(eventKindLabel('holiday'), '假日');
assert.equal(eventSubtitle({ event_type: 'holiday', event_date: '2026-08-31' }, '王老師'), '2026-08-31｜全分校');
assert.equal(eventSubtitle({ event_type: 'leave', event_date: '2026-08-31' }, '王老師'), '2026-08-31｜王老師');

console.log('teacher eligibility display tests passed');
