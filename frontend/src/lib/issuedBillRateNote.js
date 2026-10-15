// In-app #408 (#4115) / #3980 root cause B. Founder Q2 = A: an issued bill does not follow a later rate
// change, and a correction must leave a record. This only compares numbers for display; it never changes a bill.
// The comparison basis is the server's `courses[].current_contract_charge` (the contract's stored Charge, which
// follows rate edits and keeps renewal discounts and session_charge deltas), so only a stale bill differs.
// Returns null whenever the note must stay hidden.
const isOpenUnpaid = (inv) => ['unpaid', ''].includes(String(inv.status ?? ''))
  && Number(inv.calculated_applied_amount || 0) === 0
  && Number(inv.paid_amount || 0) === 0
  && !inv.zero_amount_reason
  // Multi-course or hand-entered lines cannot be compared with one contract.
  && inv.single_course_items === true;

export function issuedBillRateNote(course, invoices) {
  const current = course?.current_contract_charge;
  if (current == null || course.paid || course.package_id) return null;
  if (String(course.schedule_mode || 'count') !== 'count') return null;
  const open = (invoices || []).filter((inv) => Number(inv.student_class_id) === Number(course.id) && inv.status !== 'void');
  // Only a single issued bill can be compared with the whole contract; several open bills need a person.
  if (open.length !== 1 || !isOpenUnpaid(open[0])) return null;
  const issued = Number(open[0].total_amount || 0);
  if (issued === Number(current)) return null;
  return { invoiceId: Number(open[0].id), issued, current: Number(current) };
}
