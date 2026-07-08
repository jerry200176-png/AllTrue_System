// 回歸測試：R49/#187/#188 共享時段家族 — 同時段不同學生不得被合併吃掉（in-app #182）
// 執行：node src/lib/classSessionsApi.test.js（已掛入 npm run test:calendar）
import assert from 'node:assert/strict';
import {
  mergeSessionViewModels,
  normalizeClassSessionsPayload,
  createSessionViewModel,
} from './classSessionsApi.js';

function materialized(over) {
  return createSessionViewModel({ kind: 'materialized', isProjected: false, ...over });
}

// 1. 1v2 共享時段：同 date+startTime、不同 studentClassId 必須兩筆都活著
{
  const a = materialized({ id: 11, studentClassId: 101, date: '2026-06-28', startTime: '10:00', endTime: '12:00', studentName: '許瀠升' });
  const b = materialized({ id: 22, studentClassId: 202, date: '2026-06-28', startTime: '10:00', endTime: '12:00', studentName: '吳湘翎' });
  const merged = mergeSessionViewModels([a], [b]);
  assert.equal(merged.length, 2, '同時段不同學生的堂次不得合併');
  const names = merged.map((r) => r.studentName).sort();
  assert.deepEqual(names, ['吳湘翎', '許瀠升'], '兩位學生都必須保留');
}

// 2. projected → materialized 同課程同 slot 的 overlay 行為不變
{
  const p = createSessionViewModel({ kind: 'projected', isProjected: true, studentClassId: 101, date: '2026-06-28', startTime: '10:00', endTime: '12:00' });
  const m = materialized({ id: 11, studentClassId: 101, date: '2026-06-28', startTime: '10:00', endTime: '12:00' });
  const merged = mergeSessionViewModels([p], [m]);
  assert.equal(merged.length, 1, '同課程同 slot 的 projected 應被 materialized 取代');
  assert.equal(merged[0].isProjected, false);
  assert.equal(merged[0].id, 11);
}

// 3. 整包 payload（跨學生）normalize 後 byClass 各課程各自保留堂次
{
  const json = {
    data: [
      { id: 11, student_class_id: 101, session_date: '2026-06-28', start_time: '10:00', end_time: '12:00', status: 'scheduled', student_name: '許瀠升' },
      { id: 22, student_class_id: 202, session_date: '2026-06-28', start_time: '10:00', end_time: '12:00', status: 'scheduled', student_name: '吳湘翎' },
    ],
  };
  const { items, byClass } = normalizeClassSessionsPayload(json);
  assert.equal(items.length, 2, '整包合併後兩位學生的堂次都必須存在');
  assert.equal((byClass['101'] || []).length, 1, '課程 101 必須保留自己的堂次');
  assert.equal((byClass['202'] || []).length, 1, '課程 202 必須保留自己的堂次');
}

// 4. 無法識別（studentClassId=0 且無 id）的列不可互吞（#1048 教訓）
{
  const x = createSessionViewModel({ studentClassId: 0, date: '2026-06-28', startTime: '10:00', isProjected: false });
  const y = createSessionViewModel({ studentClassId: 0, date: '2026-06-28', startTime: '10:00', isProjected: false });
  const merged = mergeSessionViewModels([x], [y]);
  assert.equal(merged.length, 2, 'unkeyable 列不得互相吞噬');
}

console.log('classSessionsApi.test.js ✅ all assertions passed');
