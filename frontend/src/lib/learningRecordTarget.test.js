import assert from 'node:assert/strict';
import { resolveDeepLinkBranchId } from './learningRecordTarget.js';

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

console.log('learningRecordTarget.test.js OK');
