// 純 Node 單元測試（#740 Step 3）— 無 Vue test-utils / jsdom。
// getTeacherColor 是有狀態 (memoized) 函式，重點測 Cache Hit / Cache Miss 與 palette 輪替。
import assert from 'node:assert/strict';
import {
  getTeacherColor,
  __resetTeacherColorCache,
  __paletteSize,
} from './teacherColor.js';

let passed = 0;
function test(name, fn) {
  __resetTeacherColorCache(); // 每個測項從乾淨快取開始，避免跨測項污染
  fn();
  passed++;
  console.log(`  ✓ ${name}`);
}

console.log('teacherColor — 有狀態配色快取');

test('falsy teacherId → 灰色 #90A4AE，且不寫入快取', () => {
  assert.equal(getTeacherColor(0), '#90A4AE');
  assert.equal(getTeacherColor(null), '#90A4AE');
  assert.equal(getTeacherColor(''), '#90A4AE');
  // 灰色 fallback 不應佔用 palette 名額：接著第一個真實 teacher 仍拿 palette[0]
  assert.equal(getTeacherColor(101), '#1E88E5');
});

test('Cache Miss：不同 teacher 依序拿 palette 前幾色', () => {
  assert.equal(getTeacherColor(1), '#1E88E5'); // palette[0]
  assert.equal(getTeacherColor(2), '#43A047'); // palette[1]
  assert.equal(getTeacherColor(3), '#E53935'); // palette[2]
});

test('Cache Hit：同一 teacherId 第二次回傳相同顏色（走快取）', () => {
  const first = getTeacherColor(7);
  const second = getTeacherColor(7);
  assert.equal(second, first);
  // 中間插入別的 teacher 也不影響原本的快取色
  getTeacherColor(8);
  assert.equal(getTeacherColor(7), first);
});

test('Cache Hit：重複呼叫不會推進 palette 索引（不浪費顏色）', () => {
  getTeacherColor(1);            // palette[0]
  getTeacherColor(1);            // hit，不前進
  getTeacherColor(1);            // hit，不前進
  assert.equal(getTeacherColor(2), '#43A047'); // 仍是 palette[1]，證明索引沒被重複呼叫推進
});

test('palette 耗盡 → 輪替回頭（不崩潰、不溢位 undefined）', () => {
  // 指派 palette 長度 + 1 個 teacher，最後一個應 wrap 回 palette[0]
  let lastColor = '';
  for (let id = 1; id <= __paletteSize + 1; id++) {
    lastColor = getTeacherColor(id);
    assert.ok(typeof lastColor === 'string' && lastColor.startsWith('#'), `id=${id} 顏色應為合法 hex`);
  }
  // 第 (size+1) 個：idx = size % size = 0 → 回到 palette[0]
  assert.equal(lastColor, '#1E88E5');
});

test('字串型別 teacherId 也能穩定快取', () => {
  const a = getTeacherColor('teacher-42');
  assert.equal(getTeacherColor('teacher-42'), a);
});

console.log(`\nteacherColor.test.js — ${passed} passed ✅`);
