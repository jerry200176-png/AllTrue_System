export const pendingLeaveStatusLabel = (status) => ({
  open: '待主任決定',
  candidate_ready: '補課候選待確認',
}[String(status || '')] || '待主任處理');

export const pendingLeaveSessionLabel = (workflow) => {
  const session = workflow?.class_session || {};
  const date = session.date || '日期未提供';
  const time = [session.start_time, session.end_time].filter(Boolean).join('–');
  return `原堂次：${date}${time ? ` ${time}` : ''}`;
};

export const buildCourseLeaveDeepLink = (workflow) => ({
  target: 'director',
  section: 'exception-workflows',
  workflowId: Number(workflow?.id || 0) || null,
});
