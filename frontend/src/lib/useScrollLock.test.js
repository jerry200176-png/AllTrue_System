import assert from 'node:assert/strict';

// Stub a minimal DOM/window so useScrollLock can run under plain node.
const bodyStyle = {
  position: '', top: '', left: '', right: '', overflow: '',
};
globalThis.document = { body: { style: bodyStyle } };
globalThis.window = {
  scrollY: 120,
  scrollTo: () => {},
};

const { lockScroll, unlockScroll, forceUnlockScroll } = await import('./useScrollLock.js');

const bodyFrozen = () =>
  document.body.style.position === 'fixed' && document.body.style.overflow === 'hidden';

// 1. 基本 lock/unlock 平衡：解鎖後 body 樣式必須清空。
lockScroll();
assert.ok(bodyFrozen(), 'lockScroll should freeze body');
unlockScroll();
assert.equal(document.body.style.position, '', 'unlockScroll restores body.position');
assert.equal(document.body.style.overflow, '', 'unlockScroll restores body.overflow');

// 2. 巢狀鎖（reference count）：兩次 lock 需對應兩次 unlock 才解凍。
lockScroll();
lockScroll();
assert.ok(bodyFrozen(), 'nested lock keeps body frozen');
unlockScroll();
assert.ok(bodyFrozen(), 'still frozen after only one of two unlocks');
unlockScroll();
assert.ok(!bodyFrozen(), 'unfrozen after balanced unlocks');

// 3. #143 回歸：洩漏的鎖（lock 後沒有對應的 unlock，例如聚焦學生時直接換頁）
//    會讓 body 永遠 position:fixed/overflow:hidden → 整頁像被灰白遮罩蓋住、無法點選。
//    forceUnlockScroll（換頁安全網）必須能一次清除，無論計數多少。
lockScroll(); // 模擬 CourseManagement 聚焦學生
lockScroll(); // 模擬其後又開了 guide tour / 其他 overlay
assert.ok(bodyFrozen(), 'leaked locks keep body frozen');
forceUnlockScroll();
assert.ok(!bodyFrozen(), '#143: forceUnlockScroll must clear leaked lock so the next page is clickable');

// 4. force reset 後計數歸零：多餘的 unlock 不可造成負數，之後 lock 仍正常。
unlockScroll();
assert.ok(!bodyFrozen(), 'unlock after force is a no-op; body stays clear');
lockScroll();
assert.ok(bodyFrozen(), 'lock works correctly again after force reset');
forceUnlockScroll();
assert.ok(!bodyFrozen(), 'cleanup');

console.log('useScrollLock.test.js: all assertions passed');
