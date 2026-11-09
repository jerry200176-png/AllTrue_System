// 回歸測試：R49/#187/#188 共享時段家族 — 同時段不同學生不得被合併吃掉（in-app #182）
// 執行：node src/lib/classSessionsApi.test.js（已掛入 npm run test:calendar）
import assert from 'node:assert/strict';
import {
  mergeSessionViewModels,
  mergeSessionsByCourse,
  normalizeClassSessionsPayload,
  createSessionViewModel,
  sessionViewModelFromSessionDatesSlot,
  sessionViewModelWithLegacyFields,
} from './classSessionsApi.js';

function materialized(over) {
  return createSessionViewModel({ kind: 'materialized', isProjected: false, ...over });
}

// In-app #528 / #5145: moving an existing ID must replace its old mutable slot,
// while an unrelated week, course, real ID or virtual occurrence stays intact.
{
  const before = materialized({ id: 7001, studentClassId: 99, date: '2026-11-09', startTime: '20:00', endTime: '22:00', status: 'scheduled', teacherId: 5 });
  for (const [date, startTime] of [
    ['2026-11-09', '19:30'], ['2026-11-09', '20:30'],
    ['2026-11-10', '19:30'], ['2026-11-16', '19:30'],
  ]) {
    const incoming = materialized({ ...before, date, startTime, endTime: '21:30' });
    assert.deepEqual(mergeSessionViewModels([before], [incoming]), [incoming], '#528: one real ID has only its latest supplied location');
  }

  const history = materialized({ id: 7000, studentClassId: 99, date: '2026-11-02', startTime: '20:00', status: 'attended' });
  const otherSlot = materialized({ id: 7002, studentClassId: 99, date: '2026-11-09', startTime: '17:00' });
  const otherCourse = materialized({ id: 7001, studentClassId: 100, date: '2026-11-09', startTime: '20:00' });
  const virtual = createSessionViewModel({ studentClassId: 99, date: '2026-11-23', startTime: '20:00', isProjected: true });
  const input = [history, before, otherSlot, virtual];
  const original = structuredClone(input);
  input.forEach(Object.freeze); Object.freeze(input);
  let cache = { 99: input, 100: [otherCourse] };
  for (const startTime of ['19:30', '18:00']) {
    const incoming = materialized({ ...before, startTime, endTime: '20:00' });
    cache = mergeSessionsByCourse(cache, { 99: [incoming] });
    assert.equal(cache[99].filter(row => row.id === 7001).length, 1);
    assert.equal(cache[99].find(row => row.id === 7001).startTime, startTime);
  }
  assert.deepEqual(input, original, 'merging must not mutate caller-owned snapshots');
  assert.deepEqual(cache[99].filter(row => row.id !== 7001), [history, otherSlot, virtual]);
  assert.deepEqual(cache[100], [otherCourse], 'a matching ID in another course is outside this identity');

  const legacy = sessionViewModelWithLegacyFields(before);
  const moved = materialized({ ...before, date: '2026-11-10', startTime: '19:30', endTime: '21:30' });
  const [latest] = mergeSessionViewModels([legacy], [moved]);
  assert.equal(latest.session_date, latest.date, 'legacy aliases must follow the current observation');
  assert.equal(latest.start_time, latest.startTime);
  assert.equal(latest.end_time, latest.endTime);
  assert.deepEqual(mergeSessionViewModels([before, otherCourse], [moved]), [otherCourse, moved], 'same numeric ID in another course must keep its slot');

  const unknown = materialized({ id: 7001, studentClassId: 0, date: before.date, startTime: before.startTime });
  assert.equal(mergeSessionViewModels([unknown], [{ ...unknown, startTime: '19:30' }]).length, 2, 'unknown course identity must not collapse different slots');
}

// Keep the existing projected/materialized slot rule, in either arrival order.
{
  const virtual = createSessionViewModel({ studentClassId: 99, date: '2026-11-09', startTime: '19:30', isProjected: true });
  const real = materialized({ id: 7001, studentClassId: 99, date: virtual.date, startTime: virtual.startTime });
  for (const [oldRows, newRows] of [[[virtual], [real]], [[real], [virtual]]]) {
    const [row] = mergeSessionViewModels(oldRows, newRows);
    assert.equal(row.id, 7001);
    assert.equal(row.isProjected, false);
  }
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

// In-app #281: production course 2483 has an attended row and an older
// cancelled-history row for 2026-08-12 18:00.  The canonical chip must not
// depend on API order or on whether class-sessions/session-dates resolves last.
{
  const attended = materialized({ id: 22123, studentClassId: 2483, date: '2026-08-12', startTime: '18:00', endTime: '20:00', status: 'attended' });
  const cancelled = materialized({ id: 25265, studentClassId: 2483, date: '2026-08-12', startTime: '18:00', endTime: '20:00', status: 'cancelled' });
  for (const rows of [[attended, cancelled], [cancelled, attended]]) {
    const [canonical] = mergeSessionViewModels([], rows);
    assert.equal(canonical.id, 22123, 'attended evidence must win over cancelled history in either order');
    assert.equal(canonical.status, 'attended');
  }
}

// A replacement scheduled row likewise remains effective over its cancelled
// predecessor, while a genuinely all-cancelled slot stays cancelled.
{
  const scheduled = materialized({ id: 42, studentClassId: 900, date: '2026-08-19', startTime: '18:00', status: 'scheduled' });
  const cancelled = materialized({ id: 41, studentClassId: 900, date: '2026-08-19', startTime: '18:00', status: 'cancelled' });
  for (const rows of [[scheduled, cancelled], [cancelled, scheduled]]) {
    assert.equal(mergeSessionViewModels([], rows)[0].status, 'scheduled');
  }
  const onlyCancelled = mergeSessionViewModels([], [cancelled, { ...cancelled, id: 43 }]);
  assert.equal(onlyCancelled.length, 1);
  assert.equal(onlyCancelled[0].status, 'cancelled');
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

// 5. 跨週 fetch 不得吃掉其他課程已載入的堂次（in-app #220/#221）：使用者停留在 7 月週
//    編輯課程 2711 的堂次時，該次 fetch 的 byClass 只涵蓋 7 月窗口、根本不包含課程
//    1123（一個沒有 7 月堂次的舊合約）。若照舊「整包覆蓋」cache，課程 1123 五月已
//    出席的堂次就會被平白清空；改成逐課程合併後，未出現在這次 patch 裡的課程必須
//    完全維持原狀。
{
  const mayCache = {
    1123: [materialized({ id: 9432, studentClassId: 1123, date: '2026-05-30', startTime: '17:00', endTime: '19:00', status: 'attended' })],
  };
  const julyPatch = {
    2711: [materialized({ id: 24528, studentClassId: 2711, date: '2026-07-25', startTime: '15:00', endTime: '17:00', status: 'leave_adjusted' })],
  };
  const merged = mergeSessionsByCourse(mayCache, julyPatch);
  assert.equal((merged['1123'] || []).length, 1, '未被本次 fetch 觸及的課程必須保留原本的堂次');
  assert.equal(merged['1123'][0].status, 'attended');
  assert.equal((merged['2711'] || []).length, 1, '本次 fetch 涵蓋的課程要拿到新資料');
}

// 6. 未物化（projected）堂次必須帶入後端給的 branch_id，不可退回 undefined→0
//    顯示成「Branch #0」（in-app #235）。materialized 已經走 sessionViewModelFromClassSessionsRow
//    正確帶 branch_id；這裡專測 projected 分支（backend 直到本次修復前從未回傳 branch_id）。
{
  const projectedRaw = {
    kind: 'projected',
    student_class_id: 3010,
    session_date: '2026-09-01',
    start_time: '18:00',
    end_time: '20:00',
    branch_id: 16,
  };
  const vm = sessionViewModelFromSessionDatesSlot(projectedRaw);
  assert.equal(vm.isProjected, true);
  assert.equal(vm.branchId, 16, 'projected 堂次要拿到 backend 給的 branch_id，不是 undefined');
}

// 7. projected 堂次沒收到 branch_id 時保持 undefined（呼叫端仍要自己決定 fallback，
//    不在這一層偷偷塞 0，避免把「沒資料」跟「真的是 0 號分校」混在一起）
{
  const projectedRaw = {
    kind: 'projected',
    student_class_id: 3010,
    session_date: '2026-09-01',
    start_time: '18:00',
  };
  const vm = sessionViewModelFromSessionDatesSlot(projectedRaw);
  assert.equal(vm.branchId, undefined);
}

// 8. Teacher weekly projection needs identity fields even before ClassSession exists.
{
  const { items } = normalizeClassSessionsPayload({
    data: [],
    projected: {
      by_class: {
        3010: [{
          kind: 'projected',
          student_class_id: 3010,
          session_date: '2026-08-29',
          start_time: '13:00',
          end_time: '15:00',
          branch_id: 16,
          student_id: 808,
          student_name: '洪睿淵',
          subject_name: '數學',
        }],
      },
    },
  });
  assert.equal(items.length, 1);
  assert.equal(items[0].studentName, '洪睿淵');
  assert.equal(items[0].subjectName, '數學');
  assert.equal(items[0].isProjected, true);
}

console.log('classSessionsApi.test.js ✅ all assertions passed');

// #3780 P1: index rows carry substitute provenance + the contract teacher (session-only calendar stubs need it).
{
  const { items } = normalizeClassSessionsPayload({ data: [
    { id: 1, student_class_id: 9, session_date: '2026-10-09', start_time: '13:00', teacher_id: 81, contract_teacher_id: 168, substitute_notice: true },
    { id: 2, student_class_id: 9, session_date: '2026-10-10', start_time: '13:00', teacher_id: 81, contract_teacher_id: 168, substitute_notice: false },
  ] });
  const byId = Object.fromEntries(items.map((r) => [r.id, r]));
  assert.equal(byId[1].substituteNotice, true);
  assert.equal(byId[2].substituteNotice, false, 'history pin: teacher differs but no substitute notice');
  assert.equal(byId[1].contractTeacherId, 168);
}
