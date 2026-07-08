export const NON_FILLABLE_LEARNING_STATUSES = new Set([
  'absent',
  'leave',
  'leave_requested',
  'leave_adjusted',
  'excused',
  'cancelled',
]);

export function buildTeacherClassParams({ branchId, perPage = 200 } = {}) {
  const params = new URLSearchParams({ per_page: String(perPage) });
  const bid = Number(branchId || 0);
  if (bid > 0) params.set('branch_id', String(bid));
  return params;
}

export function learningSessionStatusLabel(status) {
  const s = String(status || '').toLowerCase();
  if (s === 'approved') return '已審';
  if (s === 'pending') return '待審';
  if (s === 'changes_requested') return '待修改';
  if (s === 'rejected') return '已退回';
  if (s === 'absent') return '缺席';
  if (s === 'leave' || s === 'leave_adjusted' || s === 'excused') return '請假';
  if (s === 'leave_requested') return '請假(待審)';
  if (s === 'cancelled') return '取消';
  if (s === 'substituted') return '代課';
  return '未填';
}

export function resolveLearningSessionState({
  sessionStatus,
  learningRecordStatus,
  recordStatus,
  isSubstituted = false,
  sessionStarted = false,
} = {}) {
  const normalizedSessionStatus = String(sessionStatus || '').toLowerCase();
  const baseStatus = String(learningRecordStatus || recordStatus || 'missing');
  let formStatus = baseStatus;

  if (normalizedSessionStatus === 'absent') formStatus = 'absent';
  if (['leave', 'leave_adjusted', 'excused'].includes(normalizedSessionStatus)) formStatus = 'leave';
  // 請假申請待審核（in-app #194 / GitHub #1099）：出缺勤管理與課表與評量必須同一認定
  // ——顯示「請假(待審)」、暫不需填評量；若審核退回，堂次回 scheduled 後自動恢復未填。
  if (normalizedSessionStatus === 'leave_requested') formStatus = 'leave_requested';
  if (normalizedSessionStatus === 'cancelled') formStatus = 'cancelled';
  if (isSubstituted) formStatus = 'substituted';

  const isNonFillable = NON_FILLABLE_LEARNING_STATUSES.has(formStatus) || isSubstituted;
  const fillLocked = isNonFillable || !sessionStarted;
  let fillLockReason = '';
  if (formStatus === 'absent') fillLockReason = '此堂已標記缺席，無需填寫評量';
  else if (formStatus === 'leave') fillLockReason = '此堂已請假，無需填寫評量';
  else if (formStatus === 'leave_requested') fillLockReason = '請假申請審核中，暫不需填寫評量';
  else if (formStatus === 'cancelled') fillLockReason = '此堂已取消，無需填寫評量';
  else if (isSubstituted) fillLockReason = '此堂已由代課老師處理';
  else if (fillLocked) fillLockReason = '上課開始後開放填寫';

  return {
    formStatus,
    label: learningSessionStatusLabel(formStatus),
    fillLocked,
    fillLockReason,
    recordIdAllowed: !isNonFillable,
    isAbsent: formStatus === 'absent',
    isLeave: formStatus === 'leave',
    isCancelled: formStatus === 'cancelled',
  };
}

export function attendanceSessionStatusLabel(status) {
  const s = String(status || '').toLowerCase();
  if (s === 'attended' || s === 'present') return '到班';
  if (s === 'late') return '遲到';
  if (s === 'absent') return '缺席';
  if (s === 'leave' || s === 'leave_adjusted' || s === 'excused') return '請假';
  if (s === 'leave_requested') return '請假(待審)';
  if (s === 'cancelled') return '取消';
  return '已處理';
}

const ATTENDED_LIKE_STATUSES = ['attended', 'present', 'late'];

function normalizeSessionRow(row) {
  const status = String(row?.status || row?.Status || '').toLowerCase();
  const rawNote = String(row?.note || row?.Note || '').trim();
  // A leave-deferral provenance note (e.g. "請假自動順延") lives on the session for
  // its whole lifetime; once the student actually attends it becomes contradictory.
  // Only surface the note marker for non-attended states. (#555)
  const statusNote = ATTENDED_LIKE_STATUSES.includes(status) ? '' : rawNote;
  return {
    class_session_id: Number(row?.id || 0),
    student_id: Number(row?.student_id || row?.StudentID || 0),
    student_class_id: Number(row?.student_class_id || row?.StudentClassID || 0),
    teacher_id: Number(row?.teacher_id || row?.TeacherID || 0),
    branch_id: Number(row?.branch_id || row?.CampusID || 0),
    session_date: String(row?.session_date || row?.SessionDate || '').slice(0, 10),
    start_time: String(row?.start_time || row?.StartTime || '').slice(0, 5),
    end_time: String(row?.end_time || row?.EndTime || '').slice(0, 5),
    student_name: row?.student_name || '',
    subject_name: row?.subject_name || '',
    teacher_name: row?.teacher_name || '',
    status,
    status_label: attendanceSessionStatusLabel(status),
    status_note: statusNote,
  };
}

export function classifyAttendanceSessionRows(rows = []) {
  const totalSlotKeys = new Set();
  const pendingSlotKeys = new Set();
  const statusSlotKeys = new Set();
  const pending = [];
  const statusRows = [];

  const totalCount = rows.filter((row) => {
    const status = String(row?.status || row?.Status || '').toLowerCase();
    return !['cancelled', 'leave', 'leave_adjusted'].includes(status);
  }).filter((row) => {
    const key = [
      Number(row?.student_class_id || row?.StudentClassID || 0),
      String(row?.session_date || row?.SessionDate || '').slice(0, 10),
      String(row?.start_time || row?.StartTime || '').slice(0, 5),
    ].join('|');
    if (totalSlotKeys.has(key)) return false;
    totalSlotKeys.add(key);
    return true;
  }).length;

  for (const row of rows) {
    const normalized = normalizeSessionRow(row);
    if (!(normalized.class_session_id > 0 && normalized.student_id > 0 && normalized.student_class_id > 0)) {
      continue;
    }
    const key = `${normalized.student_class_id}|${normalized.session_date}|${normalized.start_time}`;
    if (normalized.status === 'scheduled') {
      if (!pendingSlotKeys.has(key)) {
        pendingSlotKeys.add(key);
        pending.push(normalized);
      }
      continue;
    }
    // leave_requested（請假待審）必須顯示在狀態列表，否則學生會從出缺勤管理整個消失，
    // 與課表與評量認定不一致（in-app #194 / GitHub #1099）。
    if (['absent', 'attended', 'late', 'present', 'leave', 'leave_requested', 'leave_adjusted', 'excused'].includes(normalized.status)) {
      if (!statusSlotKeys.has(key)) {
        statusSlotKeys.add(key);
        statusRows.push(normalized);
      }
    }
  }

  const byStart = (a, b) => (a.start_time || '').localeCompare(b.start_time || '');
  return {
    totalCount,
    pending: pending.sort(byStart),
    statusRows: statusRows.sort(byStart),
  };
}
