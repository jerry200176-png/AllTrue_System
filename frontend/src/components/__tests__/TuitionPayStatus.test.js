import { describe, expect, it } from 'vitest';
import { collectionSummary, payStatusLabel } from '../../lib/tuitionPayStatus';

describe('tuitionPayStatus (in-app #447)', () => {
  it('labels every backend status', () => {
    expect(['paid', 'partial', 'unpaid', 'review', 'x'].map(payStatusLabel)).toEqual(['已收', '部分', '未收', '待確認', '—']);
  });
  it('shows 已收 X / 應收 Y，未收 N 人 while something is outstanding', () => {
    expect(collectionSummary({ total_due: 30000, total_collected: 13000, total_outstanding: 17000, unpaid_students: 3 }))
      .toEqual({ allCollected: false, text: '已收 13,000 / 應收 30,000，未收 3 人' });
  });
  it('shows 本月學收已收齊 when nothing is outstanding', () => {
    expect(collectionSummary({ total_due: 6000, total_collected: 6000, total_outstanding: 0, unpaid_students: 0 }))
      .toEqual({ allCollected: true, text: '本月學收已收齊' });
  });
  it('does not claim 已收齊 for an empty month', () => {
    expect(collectionSummary({}).allCollected).toBe(false);
  });
});
