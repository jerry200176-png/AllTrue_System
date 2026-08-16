import assert from 'assert';
import { attendanceMarkConfirmHint } from './attendanceMarkConfirmHint.js';

assert.equal(
  attendanceMarkConfirmHint('leave'),
  '請假將不扣堂並順延課程。',
);
assert.equal(
  attendanceMarkConfirmHint('excused'),
  '請假將不扣堂並順延課程。',
);
assert.equal(
  attendanceMarkConfirmHint('absent'),
  '缺席將不扣堂，也不順延課程。',
);
assert.equal(attendanceMarkConfirmHint('present'), '');
assert.ok(!attendanceMarkConfirmHint('absent').includes('將扣堂'));
