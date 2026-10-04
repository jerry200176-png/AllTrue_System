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
  // F10: compact failure/build context, only present when the reporter sent it.
  const buildSha = boundedText(parsed.buildSha, 40);
  if (buildSha) context.buildSha = buildSha;
  if (Array.isArray(parsed.recentApiFailures)) {
    const lines = parsed.recentApiFailures.slice(-5).map((f) => (f && typeof f === 'object'
      ? [boundedText(f.method, 10), boundedText(f.path, 120), f.status, boundedText(f.requestId, 64)]
        .filter((x) => x !== '' && x != null).join(' ')
      : '')).filter(Boolean);
    if (lines.length) context.recentApiFailures = lines;
  }
  // F15: breadcrumbs — what the reporter pressed and what the screen told them.
  if (Array.isArray(parsed.recentClicks)) {
    const labels = parsed.recentClicks.slice(-15).map((c) => boundedText(c?.label, 30)).filter(Boolean);
    if (labels.length) context.recentClicks = labels;
  }
  if (Array.isArray(parsed.recentMessages)) {
    const lines = parsed.recentMessages.slice(-5).map((m) => [boundedText(m?.kind, 10), boundedText(m?.text, 120)].filter(Boolean).join('：')).filter(Boolean);
    if (lines.length) context.recentMessages = lines;
  }
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

const MACHINE_NOTE_MARKERS = ['[product_disposition]', '[resolution_evidence]'];

/** Strip machine status-log markers for human display (compat with raw notes). */
export function stripBugStatusMachineMarkers(note) {
  if (typeof note !== 'string' || !note) return '';
  const kept = note.split(/\r\n|\r|\n/).filter((line) => {
    const trim = line.trimStart();
    return !MACHINE_NOTE_MARKERS.some((marker) => trim.startsWith(marker));
  });
  return kept.join('\n').trim();
}

/**
 * Prefer API note_display when present (including empty string).
 * If the field is missing (legacy payload), strip markers from raw note so
 * historical plain text remains while machine JSON is not shown.
 */
export function statusLogDisplayNote(log) {
  if (!log || typeof log !== 'object') return '';
  if (Object.prototype.hasOwnProperty.call(log, 'note_display')) {
    return String(log.note_display || '').trim();
  }
  return stripBugStatusMachineMarkers(String(log.note || ''));
}

const AUTO_TITLE_RE = /^\[[^\]]*\]\s*\d{4}\/\d{1,2}\/\d{1,2}\s/;
const AUTO_TITLE_PAGE_RE = /^\[([^\]]*)\]/;

/**
 * 列表標題：自動產生的「[page] 時間」標題改顯示說明第一行（列表 API 若有回傳 description/摘要）。
 * 列表 API 回傳 description_snippet（首行、≤60 字）；缺少時退回頁面名稱。
 */
export function bugListDisplay(bug, pageLabelFor = (k) => k) {
  const title = String(bug?.title || '');
  if (!AUTO_TITLE_RE.test(title)) return { title, pageLabel: '' };
  const key = bug?.page_key || title.match(AUTO_TITLE_PAGE_RE)?.[1] || '';
  const pageLabel = pageLabelFor(key) || key;
  const text = String(bug?.description_snippet ?? bug?.description ?? '').trim().split(/\r?\n/)[0].trim();
  if (!text) return { title: pageLabel ? `${pageLabel}（未填標題）` : title, pageLabel: '' };
  return { title: text.length > 40 ? `${text.slice(0, 40)}…` : text, pageLabel };
}
