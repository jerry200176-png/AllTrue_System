import { describe, expect, it } from 'vitest';
import { findLongLabels } from '../../../scripts/design/status-label-length.mjs';

describe('status-label-length advisory', () => {
  it('flags a status label of 7 characters and passes one of 6', () => {
    expect(findLongLabels("export const A_STATUS_LABELS = { a: '待確認入帳啦啦', b: '待確認入帳啦' };")).toEqual(['待確認入帳啦啦']);
  });
  it('counts ASCII, reads multi-line maps and badge markup, ignores toasts', () => {
    const text = [
      'export const X_STATUS_LABELS = {',
      "  a: '已收ABCDE',",
      "  b: '已收',",
      '};',
      '<span class="acct-chip">月結帳單與上課堂數不符</span>',
      '<span class="ledger-chip" :title="\'已收（多出部分未入帳）\'">已收</span>',
      "showToast(`${REPORT_STATUS_LABELS.pending}` + '請先勾選未繳課程');",
    ].join('\n');
    expect(findLongLabels(text).sort()).toEqual(['已收ABCDE', '月結帳單與上課堂數不符']);
  });
});
