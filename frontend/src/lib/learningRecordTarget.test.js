import assert from 'node:assert/strict';
import { resolveDeepLinkBranchId, shouldLiftDefaultWindowForDate } from './learningRecordTarget.js';

// 核心回歸 (#54 / #82)：深連結帶的目標分校與目前分校不同時，必須用「目標分校」查課次，
// 否則跨分校的補填提醒點進去會查無該堂。
{
  const r = resolveDeepLinkBranchId({ targetBranchId: 3, currentBranchId: 1 });
  assert.equal(r, 3, '應優先用深連結帶入的目標分校，而非尚未切換完成的目前分校');
}

// 同分校：兩者一致時自然回該分校
{
  assert.equal(resolveDeepLinkBranchId({ targetBranchId: 2, currentBranchId: 2 }), 2);
}

// 深連結未帶分校 → 退回目前分校
{
  assert.equal(resolveDeepLinkBranchId({ targetBranchId: null, currentBranchId: 5 }), 5);
  assert.equal(resolveDeepLinkBranchId({ targetBranchId: 0, currentBranchId: 5 }), 5);
  assert.equal(resolveDeepLinkBranchId({ currentBranchId: 5 }), 5);
}

// 字串輸入應被正確轉型（query string / localStorage 取出常是字串）
{
  assert.equal(resolveDeepLinkBranchId({ targetBranchId: '4', currentBranchId: '1' }), 4);
}

// 無效目標分校（NaN / 負數）退回目前分校
{
  assert.equal(resolveDeepLinkBranchId({ targetBranchId: 'abc', currentBranchId: 1 }), 1);
  assert.equal(resolveDeepLinkBranchId({ targetBranchId: -3, currentBranchId: 1 }), 1);
}

// 兩者皆無 → null（呼叫端據此略過查詢）
{
  assert.equal(resolveDeepLinkBranchId({}), null);
  assert.equal(resolveDeepLinkBranchId({ targetBranchId: 0, currentBranchId: 0 }), null);
}

// shouldLiftDefaultWindowForDate (#105 UX)
// 存檔的評量早於視窗起點 → 需解除視窗，否則新增後會被濾掉看不到
{
  assert.equal(shouldLiftDefaultWindowForDate({ savedDate: '2026-01-10', windowStart: '2026-03-01' }), true);
}
// 視窗內 → 不需解除
{
  assert.equal(shouldLiftDefaultWindowForDate({ savedDate: '2026-03-15', windowStart: '2026-03-01' }), false);
  assert.equal(shouldLiftDefaultWindowForDate({ savedDate: '2026-03-01', windowStart: '2026-03-01' }), false);
}
// 未套用視窗（windowStart 空，例如待審 tab 或已關閉視窗）→ 永不解除
{
  assert.equal(shouldLiftDefaultWindowForDate({ savedDate: '2026-01-10', windowStart: '' }), false);
  assert.equal(shouldLiftDefaultWindowForDate({ savedDate: '2026-01-10' }), false);
}
// 無有效日期 → 不解除（避免誤觸）
{
  assert.equal(shouldLiftDefaultWindowForDate({ savedDate: '', windowStart: '2026-03-01' }), false);
  assert.equal(shouldLiftDefaultWindowForDate({ windowStart: '2026-03-01' }), false);
}
// 含時間字串會被切到日期再比較
{
  assert.equal(shouldLiftDefaultWindowForDate({ savedDate: '2026-01-10T19:00:00', windowStart: '2026-03-01' }), true);
}

console.log('learningRecordTarget.test.js OK');
