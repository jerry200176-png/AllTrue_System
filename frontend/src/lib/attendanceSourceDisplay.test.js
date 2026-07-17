import assert from 'node:assert/strict';
import { describeSessionAttendanceSource } from './attendanceSourceDisplay.js';

assert.equal(
  describeSessionAttendanceSource({ status: 'scheduled', teacherName: '陳暉諺' }),
  null,
  '授課老師不可被誤標成點名人'
);

assert.deepEqual(
  describeSessionAttendanceSource({ status: 'attended', teacherName: '陳暉諺' }),
  { kind: 'system', label: '系統堂次狀態（無出勤紀錄）' }
);

assert.deepEqual(
  describeSessionAttendanceSource({ status: 'attended', attendanceMemo: 'swipe-rfid' }),
  { kind: 'attendance', label: 'RFID 刷卡' }
);

assert.deepEqual(
  describeSessionAttendanceSource({ status: 'attended', attendanceSignInAt: '2026-07-14T16:00:00', recordedByName: '王主任' }),
  { kind: 'attendance', label: '王主任' }
);

console.log('attendanceSourceDisplay.test.js: all assertions passed');
