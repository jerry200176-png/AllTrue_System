/**
 * Course-management session planning status (F4 UX).
 * Separates package pool entitlement from per-course schedule rows.
 * Does NOT compute package "還可排 N" without allocation aggregate.
 */

export function isSessionModeCourse(course) {
  const paymentType = String(course?.payment_type || '').trim();
  if (paymentType) return paymentType === 'session';
  return Number(course?.sessions_purchased ?? course?.SessionCount ?? 0) > 0;
}

export function getCoursePurchasedSessions(course) {
  return Math.max(0, Number(course?.sessions_purchased ?? course?.SessionCount ?? 0) || 0);
}

/** Mirrors ClassSessionController::ensureProjected (ScheduleMode=date only). */
export function canMaterializeProjectedSession(course) {
  if (!course) return false;
  if (Number(course?.Stop ?? course?.stop ?? 0) === 1) return false;
  const mode = String(course?.ScheduleMode ?? course?.schedule_mode ?? '').trim().toLowerCase();
  if (mode === 'count') return false;
  if (mode === 'date') return true;
  const pay = String(course?.payment_type || '').trim().toLowerCase();
  if (pay === 'monthly') return true;
  if (pay === 'session') return false;
  return false;
}

export function buildSessionPlanningStatus({
  course,
  effectiveCount = 0,
  leaveCount = 0,
  sessionLoadFailed = false,
  hasAnySessionRows = false,
} = {}) {
  if (sessionLoadFailed) {
    return {
      code: 'session_load_failed',
      severity: 'danger',
      title: '堂次載入失敗',
      message: '目前無法確認這堂課的最新狀態，尚未進行任何變更。',
      action: 'reload_sessions',
      counts: {},
    };
  }
  if (!isSessionModeCourse(course)) return null;

  const isPackage = !!course?.PackageID;
  const poolTotal = isPackage ? Math.max(0, Number(course?.package_total_sessions ?? 0) || 0) : 0;
  const poolRemaining = isPackage ? Math.max(0, Number(course?.package_remaining_sessions ?? 0) || 0) : 0;
  const poolUsed = isPackage ? Math.max(0, poolTotal - poolRemaining) : 0;
  const purchased = isPackage ? poolTotal : getCoursePurchasedSessions(course);
  const effective = Math.max(0, Number(effectiveCount) || 0);
  const leaves = Math.max(0, Number(leaveCount) || 0);
  if (purchased <= 0) return null;

  const counts = {
    poolTotal, poolUsed, poolRemaining, courseScheduled: effective, purchased, pendingMakeups: leaves,
  };

  if (!hasAnySessionRows && effective === 0) {
    return {
      code: 'empty_schedule',
      severity: 'info',
      title: '此科尚未排入堂次',
      message: isPackage
        ? `方案池仍有 ${poolRemaining} 堂未使用。新增排課後，日期會顯示在這裡。`
        : `購買 ${purchased} 堂；新增排課後，日期會顯示在這裡。`,
      action: 'quick_add',
      counts,
    };
  }

  if (effective > purchased) {
    const overBy = effective - purchased;
    return {
      code: 'over_quota',
      severity: 'danger',
      title: isPackage ? `此課程可能超排 ${overBy} 堂` : `已超出購買堂數 ${overBy} 堂`,
      message: isPackage
        ? '已排且會占用額度的堂次超過方案可用堂數，請先檢查排程。'
        : '請取消多餘堂次、改為例外堂，或建立新批次。',
      action: 'inspect_over_quota',
      counts: { ...counts, overBy },
    };
  }

  if (leaves > 0 && effective < purchased) {
    return {
      code: 'pending_makeup',
      severity: 'warning',
      title: `有 ${leaves} 堂請假待補`,
      message: '請安排新的補課日期；完成後待補數會自動更新。',
      action: 'arrange_makeup',
      counts,
    };
  }

  if (effective === purchased) {
    return { code: 'healthy', severity: 'none', title: '', message: '', action: null, counts };
  }

  if (isPackage) {
    return {
      code: 'package_partially_scheduled',
      severity: 'info',
      title: '目前只排定部分堂次',
      message: `方案尚有 ${poolRemaining} 堂未使用；未排滿不代表資料異常。`,
      action: 'quick_add',
      counts,
    };
  }

  const unscheduled = purchased - effective;
  return {
    code: 'course_partially_scheduled',
    severity: 'info',
    title: '尚未排滿',
    message: `已排 ${effective}／購買 ${purchased} 堂，尚有 ${unscheduled} 堂未安排。`,
    action: 'quick_add',
    counts: { ...counts, unscheduled },
  };
}

/** @deprecated Prefer buildSessionPlanningStatus. */
export function planningStatusToLegacyWarning(status) {
  if (!status || status.code === 'healthy' || status.severity === 'none') return null;
  if (status.code === 'session_load_failed') return null;
  const typeMap = {
    over_quota: 'over',
    pending_makeup: 'under_leave',
    package_partially_scheduled: 'under_other',
    course_partially_scheduled: 'under_other',
    empty_schedule: 'under_other',
  };
  return {
    show: true,
    type: typeMap[status.code] || 'under_other',
    message: status.title || status.message,
    severity: status.severity,
    code: status.code,
  };
}
