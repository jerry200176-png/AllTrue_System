import { describe, expect, it } from 'vitest';
import {
  buildCourseLeaveDeepLink,
  pendingLeaveSessionLabel,
  pendingLeaveStatusLabel,
} from '../../../lib/courseLeaveWorkflowDisplay.js';

describe('course leave workflow display contract', () => {
  it('maps workflow statuses to director-facing labels', () => {
    expect(pendingLeaveStatusLabel('open')).toBe('待主任決定');
    expect(pendingLeaveStatusLabel('candidate_ready')).toBe('補課候選待確認');
    expect(pendingLeaveStatusLabel('unknown')).toBe('待主任處理');
  });

  it('keeps the original session date and time in the case summary', () => {
    expect(pendingLeaveSessionLabel({
      class_session: { date: '2026-08-02', start_time: '10:00', end_time: '11:00' },
    })).toBe('原堂次：2026-08-02 10:00–11:00');
    expect(pendingLeaveSessionLabel({})).toBe('原堂次：日期未提供');
  });

  it('maps a case to the only supported director deep-link target', () => {
    expect(buildCourseLeaveDeepLink({ id: 27 })).toEqual({
      target: 'director',
      section: 'exception-workflows',
      workflowId: 27,
    });
    expect(buildCourseLeaveDeepLink({ id: null }).workflowId).toBeNull();
  });
});
