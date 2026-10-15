// In-app #408 (#4115) / #3980 root cause B. Founder Q2 = A: an issued bill does not follow a later price change, and
// a correction must leave a record. Founder D3: a different amount alone is no reason to void. This only puts two
// numbers side by side (neutral copy, no void call to action) and never changes a bill.
// Shown only when the server has issue-time evidence that the bill was priced at the contract's Charge then
// (`priced_from_contract`); a typed, negotiated, instalment or legacy bill is never compared. Even then the
// difference may be an agreed change, so the copy does not call the bill wrong.
// Returns null whenever the note must stay hidden.
const isOpenUnpaid = (inv) => ['unpaid', ''].includes(String(inv.status ?? ''))
  && Number(inv.calculated_applied_amount || 0) === 0
  && Number(inv.paid_amount || 0) === 0
  && !inv.zero_amount_reason
  // Multi-course or hand-entered lines cannot be compared with one contract.
  && inv.single_course_items === true
  // D3: only with issue-time evidence that the bill was priced at the contract Charge; a missing flag hides it.
  && inv.priced_from_contract === true;

export function issuedBillRateNote(course, invoices) {
  const current = course?.current_contract_charge;
  // A zero Charge (free or not yet priced) is no basis to suggest voiding a bill.
  if (current == null || Number(current) <= 0 || course.paid || course.package_id) return null;
  if (String(course.schedule_mode || 'count') !== 'count') return null;
  const open = (invoices || []).filter((inv) => Number(inv.student_class_id) === Number(course.id) && inv.status !== 'void');
  // Only a single issued bill can be compared with the whole contract; several open bills need a person.
  if (open.length !== 1 || !isOpenUnpaid(open[0])) return null;
  const issued = Number(open[0].total_amount || 0);
  if (issued === Number(current)) return null;
  return { invoiceId: Number(open[0].id), issued, current: Number(current) };
}
