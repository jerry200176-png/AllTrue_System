import { describe, expect, it } from 'vitest';
import { deleteRecordConfirmText } from '../../lib/learningRecordDeleteConfirm.js';

describe('deleteRecordConfirmText', () => {
  const rec = { student_name: 'S', SessionDate: '2026-11-01' };
  it('warns about the reversal for an approved record', () => {
    expect(deleteRecordConfirmText({ ...rec, Status: 'approved' })).toContain('這筆已核准；刪除會一併退回這堂的出席、扣堂與鐘點');
  });
  it('stays plain for other statuses', () => {
    expect(deleteRecordConfirmText({ ...rec, Status: 'pending' })).not.toContain('已核准');
  });
});
