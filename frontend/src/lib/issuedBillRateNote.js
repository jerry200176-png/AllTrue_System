// In-app #408 (#4115) / #3980 root cause B. Founder Q2 = A: an issued bill does not follow a later rate
// change, and a correction must leave a record. This only compares numbers for display; it never changes a bill.
// The estimate comes from the server (`courses[].current_rate_estimate` on accounting/ledger: Rate × SessionCount,
// the same formula the billing-correction endpoint checks). Returns null whenever the note must stay hidden.
const isOpenUnpaid = (inv) => ['unpaid', ''].includes(String(inv.status ?? ''))
  && Number(inv.calculated_applied_amount || 0) === 0
  && Number(inv.paid_amount || 0) === 0
  && !inv.zero_amount_reason;

export function issuedBillRateNote(course, invoices) {
  const est = course?.current_rate_estimate;
  if (!est || course.paid || course.package_id) return null;
  if (String(course.schedule_mode || 'count') !== 'count') return null;
  const open = (invoices || []).filter((inv) => Number(inv.student_class_id) === Number(course.id) && inv.status !== 'void');
  // Only a single issued bill can be compared with the whole contract; several open bills need a person.
  if (open.length !== 1 || !isOpenUnpaid(open[0])) return null;
  const issued = Number(open[0].total_amount || 0);
  const estimate = Number(est.amount || 0);
  if (issued === estimate) return null;
  return { invoiceId: Number(open[0].id), issued, estimate, rate: Number(est.rate || 0), count: Number(est.session_count || 0) };
}
