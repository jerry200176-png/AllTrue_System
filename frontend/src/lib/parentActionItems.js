/**
 * Build the small set of parent-facing items that deserve attention on the
 * dashboard. This is intentionally a read-only projection of the existing
 * dashboard contract; it must not invent a workflow state or expose IDs.
 */
export function buildParentActionItems({
  progressSummary = null,
  paymentAlerts = [],
  upcomingSessions = [],
  learningRecords = [],
} = {}) {
  const pendingActions = Array.isArray(progressSummary?.pending_actions)
    ? progressSummary.pending_actions
    : [];
  const pendingByKey = (key) => pendingActions.find((item) => item?.key === key);
  const countFor = (key, fallback = 0) => {
    const value = Number(pendingByKey(key)?.count);
    return Number.isFinite(value) && value > 0 ? value : fallback;
  };
  const items = [];

  const leaveCount = (Array.isArray(upcomingSessions) ? upcomingSessions : [])
    .filter((session) => {
      const status = String(session?.Status || '').toLowerCase();
      const workflow = String(session?.LeaveWorkflowStatus || '').toLowerCase();
      return status === 'leave_requested' || ['open', 'processing', 'candidate_ready'].includes(workflow);
    }).length;
  if (leaveCount > 0) {
    items.push({
      key: 'leave',
      target: 'schedule',
      icon: 'event_busy',
      tone: 'info',
      title: '請假申請等待確認',
      detail: `${leaveCount} 堂課正在等待補習班確認。`,
      action: '查看課表',
    });
  }

  const unreadReplyCount = (Array.isArray(learningRecords) ? learningRecords : [])
    .filter((record) => record?.parent_feedback?.has_unread_reply).length;
  if (unreadReplyCount > 0) {
    items.push({
      key: 'feedback_reply',
      target: 'learning',
      icon: 'mark_chat_unread',
      tone: 'success',
      title: '收到老師或主任的新回覆',
      detail: `${unreadReplyCount} 則回覆等您查看。`,
      action: '查看學習',
    });
  }

  const paymentCount = Array.isArray(paymentAlerts) && paymentAlerts.length > 0
    ? paymentAlerts.length
    : countFor('payment');
  if (paymentCount > 0) {
    items.push({
      key: 'payment',
      target: 'billing',
      icon: 'receipt_long',
      tone: 'warning',
      title: '帳務有提醒',
      detail: `${paymentCount} 筆項目需要查看。`,
      action: '查看帳務',
    });
  }

  const feedbackCount = countFor('feedback');
  if (feedbackCount > 0) {
    items.push({
      key: 'feedback',
      target: 'learning',
      icon: 'rate_review',
      tone: 'neutral',
      title: '有學習評量可以留言',
      detail: `${feedbackCount} 堂課可以補充給老師。`,
      action: '前往留言',
    });
  }

  const nextSession = progressSummary?.next_session;
  if (nextSession?.is_today) {
    const subject = String(nextSession.subject || '課程').trim();
    const start = String(nextSession.start_time || '').trim();
    items.push({
      key: 'today_session',
      target: 'schedule',
      icon: 'today',
      tone: 'today',
      title: '今天有課',
      detail: start ? `${subject}，${start} 開始。` : `${subject}，請查看課表。`,
      action: '查看課表',
    });
  }

  return items.slice(0, 5);
}
