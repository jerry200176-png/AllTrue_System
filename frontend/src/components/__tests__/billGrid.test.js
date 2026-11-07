import { afterEach, describe, expect, it, vi } from 'vitest';
import { billTotals, billTsv, loadGridPrefs, saveGridPrefs } from '../../lib/billGrid.js';

const b = (o) => ({ student_name: '甲', subject: '數學', period: '剩 4 堂', label: '未繳', owed: 1000, src: { payable_amount: 5000, paid_amount: 4000 }, ...o });

describe('billGrid', () => {
  it('totals the rows given (the filtered ones)', () => {
    expect(billTotals([b({}), b({ owed: 500, src: { payable_amount: 2000, paid_amount: 1500 } })])).toEqual({ count: 2, payable: 7000, paid: 5500, owed: 1500 });
    expect(billTotals([])).toEqual({ count: 0, payable: 0, paid: 0, owed: 0 });
  });
  it('TSV has a header, one tab-joined line per row and no stray tabs or newlines in cells', () => {
    const t = billTsv([b({ student_name: '甲\t乙\n丙' })], () => '—').split('\n');
    expect(t).toHaveLength(2);
    expect(t[0]).toBe('學生\t科目\t期間／剩幾堂\t狀態\t到期／逾期\t未收');
    expect(t[1]).toBe('甲 乙 丙\t數學\t剩 4 堂\t未繳\t—\t1000');
  });
  it('quotes text that could run as a formula, but not numbers', () => {
    const line = (o) => billTsv([b(o)], () => '—').split('\n')[1].split('\t');
    expect(line({ student_name: '=1+1' })[0]).toBe("'=1+1");
    expect(line({ student_name: '@SUM(A1)' })[0]).toBe("'@SUM(A1)");
    expect(line({ student_name: '-5' })[0]).toBe("'-5");
    expect(line({ student_name: '\t=1' })[0]).toBe("'  =1".replace('  ', ' '));
    expect(line({ owed: -5 })[5]).toBe('-5');
  });
  describe('remembered filter', () => {
    afterEach(() => vi.unstubAllGlobals());
    it('round-trips per user', () => {
      saveGridPrefs(7, { filter: 'owing' });
      expect(loadGridPrefs(7)).toEqual({ filter: 'owing' });
      expect(loadGridPrefs(8)).toEqual({});
    });
    it('survives a throwing localStorage', () => {
      vi.stubGlobal('localStorage', { getItem() { throw new Error('denied'); }, setItem() { throw new Error('quota'); } });
      expect(loadGridPrefs(1)).toEqual({});
      expect(() => saveGridPrefs(1, { filter: 'x' })).not.toThrow();
    });
  });
});
