const positiveCount = (value) => {
  const count = Number(value);
  return Number.isFinite(count) && count > 0 ? Math.floor(count) : 0;
};

const riskDefinitions = [
  {
    key: 'breached-tasks',
    signal: 'breachedTasks',
    priority: 10,
    tone: 'danger',
    icon: 'priority_high',
    title: (count) => `${count} 項營運待辦已逾期`,
    reason: '已超過處理期限，先確認責任項目與阻塞原因。',
    actionLabel: '查看逾期待辦',
    target: 'director-tasks',
  },
  {
    key: 'stranded-paid',
    signal: 'strandedSessions',
    priority: 12,
    tone: 'danger',
    icon: 'event_busy',
    title: (count) => `${count} 堂已付餘額尚未排課`,
    reason: '家長已付款但未來行事曆看不到課——今日最優先的信任問題。',
    actionLabel: '查看課表',
    target: 'calendar',
  },
  {
    key: 'dormant-hold',
    signal: 'dormantStudents',
    priority: 15,
    tone: 'warning',
    icon: 'hourglass_top',
    title: (count) => `${count} 位已付休眠需聯繫`,
    reason: '保留資格、不要自動排課；請定期關心是否恢復上課。',
    actionLabel: '查看課程管理',
    target: 'course-mgmt',
  },
  {
    key: 'unpaid-payments',
    signal: 'unpaidPayments',
    priority: 20,
    tone: 'danger',
    icon: 'payments',
    title: (count) => `${count} 筆課程尚未繳費`,
    reason: '直接影響收款與續課，請先確認聯繫狀態。',
    actionLabel: '查看繳費名單',
    target: 'tuition',
  },
  {
    key: 'pending-attendance',
    signal: 'pendingAttendance',
    priority: 30,
    tone: 'warning',
    icon: 'how_to_reg',
    title: (count) => `${count} 堂今日課程待點名`,
    reason: '今日出缺勤尚未完成，可能影響評量與堂數紀錄。',
    actionLabel: '前往點名',
    target: 'attendance',
  },
  {
    key: 'exception-workflows',
    signal: 'exceptionWorkflows',
    priority: 40,
    tone: 'warning',
    icon: 'event_repeat',
    title: (count) => `${count} 筆補課案件待安排`,
    reason: '家長請假後仍待確認補課方式與時段。',
    actionLabel: '安排補課',
    target: 'exceptions',
  },
  {
    key: 'pending-evaluations',
    signal: 'pendingEvaluations',
    priority: 50,
    tone: 'info',
    icon: 'rate_review',
    title: (count) => `${count} 筆評量等待審核`,
    reason: '核准後家長才能取得完整的學習進度。',
    actionLabel: '前往審核',
    target: 'evaluations',
  },
  {
    key: 'unread-feedback',
    signal: 'unreadFeedback',
    priority: 60,
    tone: 'info',
    icon: 'mark_unread_chat_alt',
    title: (count) => `${count} 筆家長回饋尚未查看`,
    reason: '及早回覆可降低家長等待與重複詢問。',
    actionLabel: '查看家長回饋',
    target: 'feedback',
  },
  {
    key: 'renewal-attention',
    signal: 'renewalAttention',
    priority: 70,
    tone: 'neutral',
    icon: 'event_upcoming',
    title: (count) => `${count} 筆課程需要續課確認`,
    reason: '包含低堂數或月結將屆，請安排續課確認。',
    actionLabel: '查看續課名單',
    target: 'tuition',
  },
];

export function buildDirectorPriorityRisks(signals = {}, limit = 3) {
  const safeLimit = Math.max(0, Math.floor(Number(limit) || 0));

  return riskDefinitions
    .map((definition) => ({
      ...definition,
      count: positiveCount(signals[definition.signal]),
    }))
    .filter((risk) => risk.count > 0)
    .sort((left, right) => left.priority - right.priority)
    .slice(0, safeLimit)
    .map(({ signal, priority, title, ...risk }) => ({
      ...risk,
      title: title(risk.count),
    }));
}
