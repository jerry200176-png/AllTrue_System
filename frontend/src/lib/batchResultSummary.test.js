import { describe, it, expect } from 'vitest';
import { summarizeBatchResults } from './batchResultSummary.js';

const rows = [{ id: 1, student_name: '甲', subject: '數學', latest_payment_report_id: 91 }, { id: 2, student_name: '乙', subject: '英文', latest_payment_report_id: 92 }];
const label = (r) => `${r.student_name} ${r.subject}`;

describe('summarizeBatchResults', () => {
  it('names the entries that failed and why (record: student_class_id)', () => {
    const s = summarizeBatchResults({ results: [{ student_class_id: 1, http_status: 200 }, { student_class_id: 2, http_status: 422, message: '這門課的款項已收齊' }] }, rows, (r) => r.id, label);
    expect(s.failedRowIds).toEqual([2]);
    expect(s.text).toContain('1 筆沒有成功');
    expect(s.text).toContain('乙 英文：這門課的款項已收齊');
    expect(s.text).not.toContain('甲');
  });
  it('matches confirm results by report id', () => {
    const s = summarizeBatchResults({ results: [{ id: 91, http_status: 422, message: 'x' }, { id: 92, http_status: 200 }] }, rows, (r) => r.latest_payment_report_id, label);
    expect(s.failedRowIds).toEqual([1]);
  });
  it('is empty when everything went through and caps the list', () => {
    expect(summarizeBatchResults({ results: [{ id: 1, http_status: 200 }] }, rows, (r) => r.id, label).text).toBe('');
    const many = Array.from({ length: 7 }, (_, i) => ({ student_class_id: 100 + i, http_status: 422, message: 'm' }));
    expect(summarizeBatchResults({ results: many }, [], (r) => r.id, label).text).toContain('還有 2 筆');
  });
});
