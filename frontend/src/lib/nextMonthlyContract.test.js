import assert from 'node:assert/strict';
import { loadNextMonthlyContract } from './nextMonthlyContract.js';
const args = { source: { StudentID: 1, Paid: 1, student_name: 'Fixture' }, targetId: 7, date: '2026-09-10', token: 'fixture-token' };
const data = { ID: 7, StudentID: 1, ScheduleMode: 'date', StartDate: '2026-09-01', EndDate: '2026-09-30', Paid: 0 };
const mock = (value) => async () => ({ ok: true, json: async () => value });
assert.equal((await loadNextMonthlyContract({ ...args, fetchImpl: mock(data) })).Paid, 0);
await assert.rejects(loadNextMonthlyContract({ ...args, fetchImpl: mock({ ...data, StudentID: 2 }) }), /學生或期間不符/);
await assert.rejects(loadNextMonthlyContract({ ...args, date: '2026-10-01', fetchImpl: mock(data) }), /學生或期間不符/);
console.log('nextMonthlyContract passed');
