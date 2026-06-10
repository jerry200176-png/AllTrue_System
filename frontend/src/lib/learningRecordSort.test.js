import assert from 'node:assert/strict';
import { learningRecordActionTier, compareLearningRecords } from './learningRecordSort.js';

// hasBody 假注入：以 record.__filled 旗標模擬正文是否已填。
const hasBody = (r) => !!(r && r.__filled);

// ── action tier ───────────────────────────────────────────────
// 待填寫且需處理 → tier 0（置頂）
{
  assert.equal(learningRecordActionTier({ Status: 'pending' }, hasBody), 0, 'pending 未填應置頂');
  assert.equal(learningRecordActionTier({ Status: 'changes_requested' }, hasBody), 0, 'changes_requested 未填應置頂');
}

// 核心回歸（in-app #155）：已核准但正文空白，不可被頂到最上。
{
  assert.equal(
    learningRecordActionTier({ Status: 'approved' }, hasBody),
    1,
    'approved 但空白應視為已完成（tier 1），不得置頂',
  );
}

// 已填寫者無論狀態都 tier 1
{
  assert.equal(learningRecordActionTier({ Status: 'pending', __filled: true }, hasBody), 1, '已填寫的 pending → tier 1');
  assert.equal(learningRecordActionTier({ Status: 'approved', __filled: true }, hasBody), 1);
}

// rejected / null 防呆
{
  assert.equal(learningRecordActionTier({ Status: 'rejected' }, hasBody), 1);
  assert.equal(learningRecordActionTier(null, hasBody), 1);
}

// ── 比較器：同 tier 內依日期新→舊 ──────────────────────────────
{
  const newer = { Status: 'approved', __filled: true, SessionDate: '2026-06-05' };
  const older = { Status: 'approved', __filled: true, SessionDate: '2026-01-02' };
  assert.ok(compareLearningRecords(newer, older, hasBody) < 0, '較新的日期應排前面');
  assert.ok(compareLearningRecords(older, newer, hasBody) > 0);
}

// 同日期依 StartTime 晚→早
{
  const late = { Status: 'approved', __filled: true, SessionDate: '2026-06-05', StartTime: '18:00' };
  const early = { Status: 'approved', __filled: true, SessionDate: '2026-06-05', StartTime: '09:00' };
  assert.ok(compareLearningRecords(late, early, hasBody) < 0, '同日較晚時段排前面');
}

// ── 端到端 bug 情境 ───────────────────────────────────────────
// 修正前：approved-empty(很舊) 會與 pending(新) 一起被頂到最上，亂序。
// 修正後：pending(待辦) 置頂；approved（含舊空白）依日期新→舊。
{
  const list = [
    { id: 'approved_empty_old', Status: 'approved', SessionDate: '2026-01-10' },          // 舊、空白、已核准
    { id: 'pending_new',        Status: 'pending',  SessionDate: '2026-06-01' },          // 待填（需動作）
    { id: 'approved_filled_recent', Status: 'approved', __filled: true, SessionDate: '2026-06-05' },
  ];
  const sorted = list.slice().sort((a, b) => compareLearningRecords(a, b, hasBody));
  assert.equal(sorted[0].id, 'pending_new', '待填（需動作）應在最上');
  assert.equal(sorted[1].id, 'approved_filled_recent', '其餘依日期新→舊：較近的已核准在前');
  assert.equal(sorted[2].id, 'approved_empty_old', '很舊的已核准空白評量應排在最後，而非被頂到最上');
}

console.log('learningRecordSort.test.js: all assertions passed');
