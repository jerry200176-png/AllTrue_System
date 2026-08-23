/**
 * #2007 phase 2: a course row can carry up to 3 independent warnings
 * (slot conflict, schedule drift / contract exception, usage-balance review).
 * Collapse them to one summary chip instead of stacking badges inline —
 * mirrors how mature list UIs (Cal.com booking rows, Frappe list indicators)
 * use a single status indicator with detail in a tooltip.
 */

const TONE_RANK = { danger: 3, warning: 2, info: 1 };

/**
 * @param {{hasSlotConflict: boolean, schedule_drift: boolean, contract_exception_count: number, usage_balance_status: string}} course
 * @param {(course: object) => string} usageBalanceWarningTitle
 * @returns {Array<{tone: string, label: string, title: string}>}
 */
export function courseRowWarningItems(course, usageBalanceWarningTitle) {
  const items = [];
  if (course?.hasSlotConflict) {
    items.push({
      tone: 'warning',
      label: '⚠ 與另一堂時段重疊',
      title: '這位學生還有另一筆進行中課程佔用同一位老師的同一時段，常見於續約時舊課程沒關閉。此堂請假／調課不會釋出對方的時段。',
    });
  }
  if (course?.schedule_drift) {
    items.push({
      tone: 'warning',
      label: '⚠ 堂次偏移',
      title: course.contract_exception_count > 0
        ? '堂次偏移（另含 ' + course.contract_exception_count + ' 堂補課例外，不受影響）。若偏移非刻意調課，請開啟「編輯」確認固定排課後按儲存，系統會自動同步偏移堂次。'
        : '未上預排堂次與固定排課（契約）的星期／時段不一致。若偏移非刻意調課，請開啟「編輯」確認固定排課後按儲存，系統會自動同步未上預排堂次。',
    });
  } else if (course?.contract_exception_count > 0) {
    items.push({
      tone: 'info',
      label: '補課例外',
      title: '含 ' + course.contract_exception_count + ' 堂非固定星期的補課／加課，不會被重建覆寫。',
    });
  }
  if (course?.usage_balance_status === 'review_required') {
    items.push({ tone: 'danger', label: '⚠ 堂數待對帳', title: usageBalanceWarningTitle(course) });
  }
  return items;
}

/**
 * Returns a 0-or-1-length array so a template can render it with a single
 * v-for instead of a v-if/v-else-if chain re-evaluating the same logic.
 */
export function courseRowWarningSummary(course, usageBalanceWarningTitle) {
  const items = courseRowWarningItems(course, usageBalanceWarningTitle);
  if (items.length === 0) return [];
  if (items.length === 1) return items;
  const worst = items.reduce((a, b) => (TONE_RANK[b.tone] > TONE_RANK[a.tone] ? b : a));
  return [{
    tone: worst.tone,
    label: `⚠ ${items.length} 個提醒`,
    title: items.map((it) => `${it.label.replace(/^⚠ /, '')}：${it.title}`).join('\n'),
  }];
}
