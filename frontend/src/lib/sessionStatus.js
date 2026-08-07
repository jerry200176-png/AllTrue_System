export const LEAVE_STATUSES = new Set(['leave', 'leave_requested', 'leave_adjusted', 'excused']);
export const FINAL_LEAVE_STATUSES = new Set(['leave', 'leave_adjusted', 'excused']);
export const NON_QUOTA_SESSION_STATUSES = new Set(['cancelled', ...LEAVE_STATUSES]);
export const SESSION_STATUS_LABELS = {
  scheduled: '排課中',
  attended: '已上',
  completed: '已上',
  late: '遲到',
  absent: '缺席',
  leave_requested: '請假待審',
  leave: '請假',
  leave_adjusted: '補請假',
  excused: '請假',
  cancelled: '已取消',
};

export const isLeaveStatus = (status) => LEAVE_STATUSES.has(String(status || '').toLowerCase());
