// Directors must know a delete of an approved record also reverses attendance, deduction and pay.
export function deleteRecordConfirmText(record) {
  const base = `確定要刪除 ${record.student_name} — ${record.SessionDate} 的評量記錄嗎？此操作無法還原。`;
  return String(record.Status || '').toLowerCase() === 'approved'
    ? `${base}\n\n這筆已核准；刪除會一併退回這堂的出席、扣堂與鐘點。`
    : base;
}
