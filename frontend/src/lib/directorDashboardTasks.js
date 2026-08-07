export const DASHBOARD_TASK_SEVERITY_ORDER = Object.freeze({
  critical: 0,
  danger: 0,
  warning: 1,
  info: 2,
  neutral: 3,
});

const numericCount = (value) => Math.max(0, Number(value || 0));

const task = ({
  id,
  type,
  severity = 'info',
  title,
  summary,
  count = 0,
  owner = '主任',
  dueAt = '今日',
  actionLabel,
  target,
  source = 'dashboard',
  sourceItem = null,
}) => ({
  id,
  type,
  severity,
  title,
  summary,
  count: numericCount(count),
  owner,
  dueAt,
  actionLabel,
  target,
  source,
  sourceItem,
});

export function sortDirectorDashboardTasks(tasks = []) {
  return tasks
    .filter(Boolean)
    .slice()
    .sort((a, b) => (
      (DASHBOARD_TASK_SEVERITY_ORDER[a.severity] ?? 9) - (DASHBOARD_TASK_SEVERITY_ORDER[b.severity] ?? 9)
      || (String(a.dueAt || '').localeCompare(String(b.dueAt || '')))
      || (String(a.owner || '').localeCompare(String(b.owner || '')))
      || (String(a.id || '').localeCompare(String(b.id || '')))
    ));
}

export function buildDirectorDashboardTasks({
  pendingAttendanceCount = 0,
  lowBalanceCount = 0,
  paymentLabel = '筆待留意',
  pendingMakeupCount = 0,
  exceptionWorkflowCount = 0,
  pendingEvaluationsCount = 0,
  unreadFeedbackCount = 0,
  scheduleDiscrepancyCount = 0,
  adoptionTasks = [],
} = {}) {
  const tasks = [];
  if (numericCount(pendingAttendanceCount)) {
    tasks.push(task({
      id: 'attendance', type: 'attendance', severity: 'critical',
      title: '今日點名尚未完成', summary: `還有 ${numericCount(pendingAttendanceCount)} 堂課需要確認到班狀態。`,
      count: pendingAttendanceCount, actionLabel: '前往點名', target: { page: 'attendance' },
    }));
  }
  if (numericCount(exceptionWorkflowCount)) {
    tasks.push(task({
      id: 'parent-leave', type: 'parent-leave', severity: 'critical',
      title: '家長請假待主任處理', summary: '先安排補課或核准請假，處理結果會回到家長端。',
      count: exceptionWorkflowCount, actionLabel: '開始處理', target: { section: 'exception-workflows' },
    }));
  }
  if (numericCount(lowBalanceCount)) {
    tasks.push(task({
      id: 'tuition', type: 'tuition', severity: 'warning',
      title: '繳費與續課提醒', summary: `${paymentLabel}需要主任跟進，避免影響後續上課。`,
      count: lowBalanceCount, actionLabel: '前往催繳', target: { page: 'tuition-collect' },
    }));
  }
  if (numericCount(pendingMakeupCount)) {
    tasks.push(task({
      id: 'makeup', type: 'makeup', severity: 'warning',
      title: '補課點名待補登', summary: '已結束的補課堂次尚未完成出缺勤紀錄。',
      count: pendingMakeupCount, actionLabel: '補點名', target: { page: 'attendance' },
    }));
  }
  if (numericCount(scheduleDiscrepancyCount)) {
    tasks.push(task({
      id: 'schedule-discrepancy', type: 'schedule-discrepancy', severity: 'warning',
      title: '課表回報待確認', summary: '老師回報的課表差異需要主任判斷並留下處理結果。',
      count: scheduleDiscrepancyCount, actionLabel: '查看回報', target: { page: 'schedule-discrepancy' },
    }));
  }
  if (numericCount(pendingEvaluationsCount)) {
    tasks.push(task({
      id: 'evaluations', type: 'evaluations', severity: 'info',
      title: '評量待審核', summary: '確認學習紀錄後，家長才能看到完整回饋。',
      count: pendingEvaluationsCount, actionLabel: '前往審核', target: { section: 'evals' },
    }));
  }
  if (numericCount(unreadFeedbackCount)) {
    tasks.push(task({
      id: 'parent-feedback', type: 'parent-feedback', severity: 'info',
      title: '家長回饋待查看', summary: '有新的家長訊息等待主任查看或回覆。',
      count: unreadFeedbackCount, actionLabel: '前往回覆', target: { page: 'learning', focus: 'feedback' },
    }));
  }

  for (const item of Array.isArray(adoptionTasks) ? adoptionTasks : []) {
    const count = numericCount(item?.blockedCount || item?.count || 0);
    const status = String(item?.status || item?.slaLevel || '').toLowerCase();
    const severity = ['breached', 'overdue'].includes(status)
      ? 'critical'
      : (status === 'warning' ? 'warning' : 'info');
    tasks.push(task({
      id: `adoption-${item?.id || item?.title || tasks.length}`,
      type: 'adoption', severity,
      title: item?.title || '營運待辦',
      summary: item?.description || `${item?.owner || '主任'}需要跟進這項工作。`,
      count, owner: item?.owner || '主任', dueAt: item?.dueAt || '今日',
      actionLabel: item?.actionLabel || '查看詳情', target: item?.target || {},
      source: 'adoption', sourceItem: item,
    }));
  }

  const seenKeys = new Set();
  return sortDirectorDashboardTasks(tasks).filter((item) => {
    const key = item.type === 'adoption' ? item.id : item.type;
    if (seenKeys.has(key)) return false;
    seenKeys.add(key);
    return true;
  });
}
