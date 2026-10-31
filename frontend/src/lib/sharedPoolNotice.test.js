import { expect, it } from 'vitest';
import { withPoolNotice } from './sharedPoolNotice.js';

it('adds each distinct used-up notice once and leaves other messages alone', () => {
  const over = { package_notice: '共用方案已超出 1 堂，請先續課。' };
  expect(withPoolNotice('已核課：王小明 出席', {})).toBe('已核課：王小明 出席');
  expect(withPoolNotice('批次完成：2 成功', over, over, null)).toBe('批次完成：2 成功 共用方案已超出 1 堂，請先續課。');
});
