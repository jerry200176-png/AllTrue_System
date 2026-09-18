const LIMITS = {
  occurrenceAt: 80,
  relatedReference: 300,
  screenSize: 40,
  timeZone: 100,
  feedbackType: 40,
};

function boundedText(value, maxLength) {
  return typeof value === 'string' ? value.trim().slice(0, maxLength) : '';
}

export function parseBugReportClientInfo(raw) {
  if (typeof raw !== 'string' || !raw.trim()) return null;

  let parsed;
  try {
    parsed = JSON.parse(raw);
  } catch {
    return null;
  }
  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) return null;

  const context = Object.fromEntries(
    Object.entries(LIMITS).map(([key, limit]) => [key, boundedText(parsed[key], limit)]),
  );
  return Object.values(context).some(Boolean) ? context : null;
}

export const PRODUCT_DISPOSITION_OPTIONS = [
  { value: 'bug', label: '缺陷／Bug' },
  { value: 'suggestion', label: '建議／功能' },
  { value: 'ux_friction', label: '操作摩擦' },
  { value: 'duplicate', label: '重複回報' },
  { value: 'already_solved', label: '已解決／已存在' },
  { value: 'not_planned', label: '暫不規劃' },
  { value: 'needs_info', label: '需更多資訊' },
];

export function dispositionLabel(kind) {
  return PRODUCT_DISPOSITION_OPTIONS.find((o) => o.value === kind)?.label || kind || '';
}

export function productLoopPhaseLabel(phase) {
  return {
    SUBMITTED: '已提交',
    TRIAGED: '已分診',
    DISPOSITIONED: '已定性',
    IN_PROGRESS: '工程處理中',
    SHIPPED: '已上線（待驗收）',
    RESOLVED_PENDING_VERIFY: '已標記解決（待驗收）',
    CLOSED: '已關閉',
  }[phase] || phase || '';
}
