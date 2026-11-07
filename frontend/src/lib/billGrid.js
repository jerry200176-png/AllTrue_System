// 帳單 grid helpers (PR-B, #5099): pure parts around TanStack Table (totals, TSV, remembered filter).

const n = (v) => Number(v) || 0;

/** Totals of the rows on screen. 應收/已收 come from the server row, 未繳 from the list's own owed. */
export function billTotals(rows) {
  return (rows || []).reduce((t, b) => ({
    count: t.count + 1,
    payable: t.payable + n(b.src?.payable_amount),
    paid: t.paid + n(b.src?.paid_amount),
    owed: t.owed + n(b.owed),
  }), { count: 0, payable: 0, paid: 0, owed: 0 });
}

const clean = (v) => String(v ?? '').replace(/[\t\r\n]+/g, ' ');

/** Tab-separated text that pastes straight into Excel (header row first). */
export function billTsv(rows, dueText) {
  const head = ['學生', '科目', '期間／剩幾堂', '狀態', '到期／逾期', '未收'];
  const body = (rows || []).map((b) => [b.student_name, b.subject, b.period, b.label, dueText(b), b.owed].map(clean).join('\t'));
  return [head.join('\t'), ...body].join('\n');
}

export async function copyText(text) {
  await navigator.clipboard.writeText(text);
}

const KEY = (userId) => `bill-grid:${userId ?? 'anon'}`;
/** Last filter per user. localStorage can throw (private mode, quota), so every access is guarded. */
export function loadGridPrefs(userId) {
  try { return JSON.parse(localStorage.getItem(KEY(userId)) || '{}') || {}; } catch { return {}; }
}
export function saveGridPrefs(userId, prefs) {
  try { localStorage.setItem(KEY(userId), JSON.stringify(prefs)); } catch { /* ignore: remembering is optional */ }
}
