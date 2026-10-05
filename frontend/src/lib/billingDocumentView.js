// View models for BillingDocument.vue (payment slip + receipt) and the small
// formatters it shares. Pure functions: no DOM, no fetch.

export function formatAmount(n) {
  return 'NT$ ' + Number(n || 0).toLocaleString('zh-TW');
}
export function formatDate(d) {
  if (!d) return '—';
  return String(d).slice(0, 10).replace(/-/g, '/');
}
const WEEKDAY = ['日', '一', '二', '三', '四', '五', '六'];
export function formatSessionDate(d) {
  const day = new Date(`${String(d).slice(0, 10)}T00:00:00`);
  return Number.isNaN(day.getTime()) ? formatDate(d) : `${formatDate(d)}（${WEEKDAY[day.getDay()]}）`;
}
export function formatTime(s) {
  if (s.start_time && s.end_time) return `${s.start_time}–${s.end_time}`;
  return s.start_time || '—';
}
export const BRAND_TITLE = '台北全真一對一補習班';
export function formatBrandTitle(campusName) {
  const branch = String(campusName || '').trim();
  return branch ? `${BRAND_TITLE}｜${branch}` : BRAND_TITLE;
}

export const STATUS_ZH = {
  attended: '已到課', completed: '已完課', late: '遲到', absent: '缺席',
  excused: '事假', scheduled: '排定', leave: '請假', leave_adjusted: '調課', expected: '預計', rescheduled: '改期',
};
const DONE = ['attended', 'completed', 'late', 'absent', 'excused'];
export function isDone(status) {
  return DONE.includes(status);
}
export function statusTone(status) {
  if (['attended', 'completed'].includes(status)) return 'done';
  if (['late', 'absent'].includes(status)) return 'warn';
  if (['leave', 'leave_adjusted', 'excused'].includes(status)) return 'leave';
  return 'planned';
}

const SLIP_FOOTER = '此通知單僅供繳費確認用，如已繳費請忽略。';

/** Payment slip (invoice slip-data or tuition-slip payload) → BillingDocument view model. */
export function paymentSlipView(raw) {
  // Per slip, not per page load: a tab left open overnight must not print yesterday.
  const generatedOn = new Date().toLocaleDateString('zh-TW');
  if (raw.invoice_id) {
    const items = (raw.items || []).map(i => ({
      description: i.description || '—',
      period: i.period_start && i.period_end
        ? `${formatDate(i.period_start)} – ${formatDate(i.period_end)}`
        : '—',
      amount: formatAmount(i.amount),
    }));
    return {
      tone: 'invoice',
      student_name: raw.student_name,
      campus_name: raw.campus_name,
      title: '繳費通知單',
      amount_label: raw.status === 'partial' ? '尚欠金額' : '應繳金額',
      amount: raw.remaining,
      sub_amounts: raw.status === 'partial'
        ? `應繳總額 ${formatAmount(raw.total_amount)}・已繳 ${formatAmount(raw.paid_amount)}`
        : null,
      ref_label: `帳單編號 #${raw.invoice_id}`,
      due: raw.due_date ? { label: '繳費期限', value: formatDate(raw.due_date) } : null,
      meta: [
        { label: '學生', value: raw.student_name || '—' },
        ...(items.length === 1 && items[0].period !== '—' ? [{ label: '服務期間', value: items[0].period }] : []),
        { label: '開立日期', value: formatDate(raw.issue_date) },
      ],
      items,
      note: raw.note,
      sessions: raw.sessions || [],
      footer_note: SLIP_FOOTER,
      generated_on: generatedOn,
      filename: `繳費單_${raw.student_name}_${raw.invoice_id}.png`,
    };
  }
  const modeLabel = raw.schedule_mode === 'date' ? '月結制' : '堂數制';
  const hasCanonicalPayable = raw.payable_status === 'invoiced' && raw.payable_amount != null;
  const displayedAmount = hasCanonicalPayable ? raw.payable_amount : (raw.estimated_amount ?? raw.charge ?? 0);
  const items = [{
    description: `${raw.subject}（${modeLabel}${hasCanonicalPayable ? '' : '・估算'}）`,
    period: raw.schedule_mode === 'date' && raw.period_sessions != null
      ? (raw.period_sessions === 0 && raw.sessions?.length
          ? `本期預計 ${raw.sessions.length} 堂`
          : `本期 ${raw.period_sessions} 堂`)
      : (raw.remaining_sessions != null ? `剩餘 ${raw.remaining_sessions} 堂` : '—'),
    amount: displayedAmount ? formatAmount(displayedAmount) : '—',
  }];

  const days = raw.days_until_settlement;
  return {
    tone: 'tuition',
    student_name: raw.student_name,
    campus_name: raw.campus_name,
    title: '繳費通知',
    amount_label: hasCanonicalPayable ? '應繳費用' : '預估金額（尚無帳單）',
    amount: displayedAmount,
    sub_amounts: null,
    ref_label: raw.subject,
    due: raw.due_date
      ? {
          label: '繳費期限',
          value: formatDate(raw.due_date),
          hint: days != null && days < 0 ? `已逾期 ${Math.abs(days)} 天` : null,
        }
      : null,
    meta: [
      { label: '學生', value: raw.student_name || '—' },
      { label: '產生日期', value: generatedOn },
    ],
    items,
    note: raw.note,
    // Count-mode payloads list the whole course, not one billing period.
    session_title: raw.schedule_mode === 'date' ? '本期上課日期' : '課程明細',
    sessions: raw.sessions || [],
    footer_note: SLIP_FOOTER,
    generated_on: generatedOn,
    filename: `繳費通知_${raw.student_name}_${raw.student_class_id}.png`,
  };
}


const METHOD_ZH = { cash: '現金', transfer: '匯款', card: '信用卡', line_pay: 'LINE Pay', backfill: '現金（補建）' };
export function paymentMethodLabel(m) {
  return METHOD_ZH[m] || m || '—';
}

/** Receipt (adaptPaymentReportReceipt output) → BillingDocument view model. */
export function receiptView(receipt) {
  const snap = receipt.content_snapshot || {};
  const period = snap.study_period ? `${snap.study_period.start} – ${snap.study_period.end}` : null;
  return {
    tone: 'receipt',
    campus_name: snap.campus_name,
    title: '電子收據',
    ref_label: `收據號碼 ${receipt.receipt_number}`,
    amount_label: '已收金額',
    amount: snap.total_amount,
    sub_amounts: null,
    due: { label: '收款日期', value: snap.paid_at || '—' },
    meta: [
      { label: '學生', value: snap.student_name || '—' },
      ...(period ? [{ label: '修業期間', value: period }] : []),
      { label: '收款方式', value: paymentMethodLabel(snap.method) },
    ],
    items: (snap.items || []).map(i => ({ description: i.description, amount: formatAmount(i.amount) })),
    total: formatAmount(snap.total_amount),
    session_title: '上課日期',
    // Receipt dates are Y/m/d; expected = purchased lessons not yet held.
    sessions: (snap.session_dates || []).map(s => ({
      ...s,
      date: String(s.date || '').replace(/\//g, '-'),
      status: s.expected ? 'expected' : 'attended',
    })),
    note: snap.note,
    sections: snap.refund_policy ? [{ label: '退費規定', text: snap.refund_policy }] : [],
    sign_line: true,
    footer_note: '此收據由 AllTrue 系統產生',
    generated_on: `開立 ${snap.confirmed_at || snap.paid_at || '—'}`,
    watermark: receipt.status === 'voided' ? '作廢' : null,
  };
}
