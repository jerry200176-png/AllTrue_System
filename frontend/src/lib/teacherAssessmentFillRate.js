const clampPercent = (value) => Math.min(100, Math.max(0, Math.round(Number(value) || 0)));

/**
 * 將主任評量完成率 API row 轉成可行動的工作台資料。
 * 業務規則：樣本不足時只提示「資料累積中」，避免用少量堂次誤判老師表現。
 */
export function normalizeTeacherAssessmentFillRate(row = {}, minimumSessions = 5) {
  const sessions = Math.max(0, Number(row.sessions_attended) || 0);
  const filled = Math.min(sessions, Math.max(0, Number(row.learning_records_filled) || 0));
  const fillRate = clampPercent(row.fill_rate_pct ?? (sessions ? (filled / sessions) * 100 : 0));
  const pending = sessions - filled;

  let status = 'building';
  if (sessions >= minimumSessions) {
    if (pending === 0) status = 'on_track';
    else if (fillRate < 70) status = 'follow_up';
    else if (fillRate < 90) status = 'watch';
    else status = 'on_track';
  }

  return {
    teacherId: Number(row.teacher_id) || 0,
    teacherName: String(row.teacher_name || '未命名老師'),
    sessions,
    filled,
    pending,
    fillRate,
    status,
  };
}

export function sortTeacherAssessmentFillRates(rows = []) {
  const statusOrder = { follow_up: 0, watch: 1, building: 2, on_track: 3 };
  return rows
    .filter(Boolean)
    .slice()
    .sort((a, b) => (
      (statusOrder[a.status] ?? 9) - (statusOrder[b.status] ?? 9)
      || b.pending - a.pending
      || a.fillRate - b.fillRate
      || a.teacherName.localeCompare(b.teacherName, 'zh-Hant')
    ));
}

export const TEACHER_ASSESSMENT_FILL_RATE_STATUS = Object.freeze({
  building: { label: '資料累積中', tone: 'neutral', description: '至少累積 5 堂後才列入跟進判斷。' },
  on_track: { label: '穩定完成', tone: 'success', description: '目前沒有需要主任介入的未填評量。' },
  watch: { label: '提醒關注', tone: 'warning', description: '有少量未填評量，建議提醒老師補齊。' },
  follow_up: { label: '需要跟進', tone: 'danger', description: '待填評量較多，建議直接協助確認原因。' },
});

export function getTeacherAssessmentFillRateStatus(status) {
  return TEACHER_ASSESSMENT_FILL_RATE_STATUS[status] || TEACHER_ASSESSMENT_FILL_RATE_STATUS.building;
}
