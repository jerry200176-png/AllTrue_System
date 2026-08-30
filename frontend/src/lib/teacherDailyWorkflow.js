export const TEACHER_LEAVE_STATUSES = ['leave', 'leave_requested', 'leave_adjusted', 'excused'];

const LEAVE_STATUS_SET = new Set(TEACHER_LEAVE_STATUSES);

const valueOf = (row, ...keys) => keys
  .map((key) => row?.[key])
  .find((value) => value !== undefined && value !== null && value !== '');

const sessionKey = (row) => valueOf(row, 'class_session_id', 'session_id', 'classSessionId', 'id');

const statusOf = (row) => String(valueOf(row, 'form_status', 'formStatus', 'status', 'session_status') || '').toLowerCase();

const isLeaveRow = (row) => LEAVE_STATUS_SET.has(statusOf(row))
  || LEAVE_STATUS_SET.has(String(row?.class_session_status || '').toLowerCase());

const timeKey = (row) => {
  const date = valueOf(row, 'session_date', 'date', 'due_at', 'dueAt');
  const time = valueOf(row, 'start_time', 'startTime') || '23:59';
  const parsed = Date.parse(`${date || '9999-12-31'}T${time}`);
  return Number.isFinite(parsed) ? parsed : Number.MAX_SAFE_INTEGER;
};

const displayStudent = (row) => valueOf(row, 'student_name', 'studentName', 'student?.name') || '未命名學生';
const displaySubject = (row) => valueOf(row, 'subject_name', 'subjectName', 'subject') || '課程';
const displayTime = (row) => {
  const date = valueOf(row, 'session_date', 'date');
  const start = valueOf(row, 'start_time', 'startTime');
  const end = valueOf(row, 'end_time', 'endTime');
  return [date, start && end ? `${start}–${end}` : start].filter(Boolean).join(' · ');
};

const taskForLearning = (row, overdue = false) => {
  const status = statusOf(row);
  const changed = status === 'changes_requested' || status === 'needs_revision';
  const id = valueOf(row, 'id', 'record_id', 'recordId', 'class_session_id', 'session_id');
  return {
    id: `learning-${id}`,
    type: 'learning',
    severity: changed || overdue ? 'urgent' : 'normal',
    title: changed ? '評量需要修改' : overdue ? '補填過期評量' : '待填評量',
    summary: `${displayStudent(row)} · ${displaySubject(row)}${displayTime(row) ? ` · ${displayTime(row)}` : ''}`,
    count: 1,
    owner: '老師',
    dueAt: valueOf(row, 'due_at', 'dueAt', 'session_date', 'date') || null,
    actionLabel: changed ? '修改評量' : '填寫評量',
    target: {
      type: 'learning',
      recordId: valueOf(row, 'record_id', 'recordId', 'id') || null,
      classSessionId: valueOf(row, 'class_session_id', 'session_id') || null,
    },
    source: row,
    _sortTime: timeKey(row),
    _sortRank: changed ? 0 : overdue ? 1 : 2,
  };
};

const taskForAttendance = (row) => ({
  id: `attendance-${sessionKey(row)}`,
  type: 'attendance',
  severity: 'normal',
  title: '待點名',
  summary: `${displayStudent(row)} · ${displaySubject(row)}${displayTime(row) ? ` · ${displayTime(row)}` : ''}`,
  count: 1,
  owner: '老師',
  dueAt: valueOf(row, 'session_date', 'date') || null,
  actionLabel: '開始點名',
  target: { type: 'attendance', classSessionId: sessionKey(row) },
  source: row,
  _sortTime: timeKey(row),
  _sortRank: 3,
});

const taskForFeedback = (row) => {
  const count = Number(valueOf(row, 'count', 'unread_count', 'unreadCount') || 1);
  return {
    id: `feedback-${valueOf(row, 'id', 'record_id', 'recordId') || 'inbox'}`,
    type: 'feedback',
    severity: 'normal',
    title: '家長回饋待處理',
    summary: `有 ${Number.isFinite(count) ? count : 1} 筆家長訊息需要查看`,
    count: Number.isFinite(count) ? count : 1,
    owner: '老師',
    dueAt: null,
    actionLabel: '查看並回覆',
    target: { type: 'feedback' },
    source: row,
    _sortTime: Number.MAX_SAFE_INTEGER,
    _sortRank: 4,
  };
};

export function buildTeacherTasks({
  pendingAttendance = [],
  pendingLearning = [],
  overdueLearning = [],
  awaitingReplies = [],
} = {}) {
  const tasks = [];
  const taskIndex = new Map();
  const addTask = (task) => {
    const existingIndex = taskIndex.get(task.id);
    if (existingIndex === undefined) {
      taskIndex.set(task.id, tasks.length);
      tasks.push(task);
      return;
    }
    const existing = tasks[existingIndex];
    if (task._sortRank < existing._sortRank) tasks[existingIndex] = task;
  };

  for (const row of overdueLearning) {
    if (!row || isLeaveRow(row)) continue;
    const task = taskForLearning(row, true);
    addTask(task);
  }
  for (const row of pendingLearning) {
    if (!row || isLeaveRow(row)) continue;
    const task = taskForLearning(row, false);
    addTask(task);
  }
  for (const row of pendingAttendance) {
    if (!row || isLeaveRow(row)) continue;
    const task = taskForAttendance(row);
    addTask(task);
  }
  const feedbackRows = Array.isArray(awaitingReplies) ? awaitingReplies : [awaitingReplies];
  for (const row of feedbackRows) {
    if (!row) continue;
    const count = Number(valueOf(row, 'count', 'unread_count', 'unreadCount') ?? row);
    if (Number.isFinite(count) && count <= 0) continue;
    const task = taskForFeedback(typeof row === 'object' ? row : { count });
    addTask(task);
  }

  return tasks.sort((a, b) => a._sortRank - b._sortRank || a._sortTime - b._sortTime)
    .map(({ _sortRank, _sortTime, ...task }) => task);
}

/**
 * Return the number of actionable items represented by the task rows.
 * A feedback row can group several messages, so row count is not item count.
 */
export function countTeacherTasks(tasks = []) {
  if (!Array.isArray(tasks)) return 0;
  return tasks.reduce((total, task) => {
    const count = Number(task?.count);
    return total + (Number.isFinite(count) ? Math.max(0, Math.trunc(count)) : 1);
  }, 0);
}
