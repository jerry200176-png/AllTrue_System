-- Review/provisioning template, not a migration. Install as the dedicated view
-- definer after verifying __SOURCE_SCHEMA__, the target release and Campus.
-- Grant only explicit SELECT on the six daan_tuition views described in README.
CREATE SQL SECURITY DEFINER VIEW alltrue_diagnostics.daan_tuition_scope AS
SELECT c.id AS campus_id, c.code AS campus_code, CAST('2026-11-01' AS DATE) AS cutoff_exclusive
FROM __SOURCE_SCHEMA__.Campus c
WHERE c.id = 15 AND BINARY c.code = 'daan' AND c.is_test = 0;

CREATE SQL SECURITY DEFINER VIEW alltrue_diagnostics.daan_tuition_contracts AS
SELECT c.ID AS id, s.CampusID AS campus_id, c.StudentID AS student_id,
       c.StartDate AS start_date, c.EndDate AS end_date, c.Charge AS charge,
       c.Paid AS paid, c.MDate AS updated_at,
       CASE c.ScheduleMode WHEN 'count' THEN 'count' WHEN 'date' THEN 'date' ELSE 'other' END AS schedule_mode,
       p.id AS package_id, p.paid AS package_paid,
       ((c.ScheduleMode IS NULL OR c.ScheduleMode = 'count') AND COALESCE(c.PackageID, 0) = 0
        AND COALESCE(c.closed_reason, '') NOT IN ('waived', 'refunded')) AS standalone_count,
       (c.PackageID IS NOT NULL AND c.PackageID <> 0 AND p.id IS NULL) AS package_link_uncertain
FROM __SOURCE_SCHEMA__.StudentClass c
JOIN __SOURCE_SCHEMA__.Student s ON s.id = c.StudentID
JOIN alltrue_diagnostics.daan_tuition_scope d ON d.campus_id = s.CampusID
LEFT JOIN __SOURCE_SCHEMA__.course_packages p
       ON p.id = c.PackageID AND p.student_id = s.id AND p.campus_id = d.campus_id
WHERE c.StartDate < d.cutoff_exclusive OR c.StartDate IS NULL;

CREATE SQL SECURITY DEFINER VIEW alltrue_diagnostics.daan_tuition_invoices AS
SELECT i.id, d.campus_id, s.id AS student_id, c.id AS contract_id,
       (i.StudentClassID IS NOT NULL AND c.id IS NULL) AS contract_link_uncertain,
       i.IssueDate AS issue_date, i.created_at, i.updated_at,
       CASE WHEN i.billing_period REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$' THEN i.billing_period END AS billing_period,
       i.TotalAmount AS amount, i.PaidAmount AS stored_paid_amount,
       CASE i.Status WHEN 'paid' THEN 'paid' WHEN 'partial' THEN 'partial'
            WHEN 'unpaid' THEN 'unpaid' WHEN 'void' THEN 'void' ELSE 'other' END AS status
FROM __SOURCE_SCHEMA__.Invoice i
JOIN __SOURCE_SCHEMA__.Student s ON s.id = i.StudentID
JOIN alltrue_diagnostics.daan_tuition_scope d ON d.campus_id = s.CampusID
LEFT JOIN alltrue_diagnostics.daan_tuition_contracts c ON c.id = i.StudentClassID AND c.student_id = s.id
WHERE i.IssueDate < d.cutoff_exclusive
   OR (i.billing_period REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$' AND i.billing_period < LEFT(d.cutoff_exclusive, 7))
   OR (i.IssueDate IS NULL AND (i.created_at < d.cutoff_exclusive OR i.created_at IS NULL))
   OR EXISTS (SELECT 1 FROM __SOURCE_SCHEMA__.Payment p WHERE p.InvoiceID = i.id AND p.PaidAt < d.cutoff_exclusive);

CREATE SQL SECURITY DEFINER VIEW alltrue_diagnostics.daan_tuition_invoice_items AS
SELECT a.id, i.campus_id, i.id AS invoice_id, c.id AS contract_id, a.Amount AS amount,
       a.PeriodStart AS period_start, a.PeriodEnd AS period_end,
       (COALESCE(c.standalone_count, 0) = 1 AND (i.contract_id IS NULL OR i.contract_id <> c.id)
        AND i.status <> 'void' AND i.amount > 0 AND a.Amount > 0) AS standalone_count_line,
       (a.StudentClassID IS NOT NULL AND c.id IS NULL) AS contract_link_uncertain
FROM __SOURCE_SCHEMA__.InvoiceItem a
JOIN alltrue_diagnostics.daan_tuition_invoices i ON i.id = a.InvoiceID
LEFT JOIN alltrue_diagnostics.daan_tuition_contracts c ON c.id = a.StudentClassID AND c.student_id = i.student_id;

CREATE SQL SECURITY DEFINER VIEW alltrue_diagnostics.daan_tuition_payments AS
SELECT p.id, i.campus_id, i.student_id, i.id AS invoice_id, r.id AS report_id, p.Amount AS amount,
       p.PaidAt AS paid_at, p.created_at, p.updated_at,
       COALESCE(incoming.created_at, outgoing.created_at) AS receipt_moved_at,
       (COALESCE(p.Note, '') LIKE '[receipt_reassign:%' AND incoming.id IS NULL AND outgoing.id IS NULL) AS receipt_move_uncertain,
       (p.payment_report_id IS NOT NULL AND r.id IS NULL) AS report_link_uncertain,
       CASE p.Method WHEN 'cash' THEN 'cash' WHEN 'transfer' THEN 'transfer' WHEN 'void' THEN 'void'
            WHEN 'refund' THEN 'refund' WHEN 'transfer_in' THEN 'transfer_in' WHEN 'transfer_out' THEN 'transfer_out'
            WHEN 'transfer_reversal_in' THEN 'transfer_reversal_in' WHEN 'transfer_reversal_out' THEN 'transfer_reversal_out'
            ELSE 'other' END AS method
FROM __SOURCE_SCHEMA__.Payment p
JOIN alltrue_diagnostics.daan_tuition_invoices i ON i.id = p.InvoiceID
LEFT JOIN __SOURCE_SCHEMA__.payment_reports r ON r.id = p.payment_report_id AND r.StudentID = i.student_id
LEFT JOIN __SOURCE_SCHEMA__.payment_report_reassignments incoming ON incoming.to_report_id = r.id
       AND incoming.campus_id = i.campus_id AND incoming.to_student_id = i.student_id AND incoming.to_invoice_id = i.id
       AND p.id = r.payment_id AND p.Amount = incoming.amount AND p.Method IN ('cash', 'transfer')
       AND p.Note LIKE CONCAT('[receipt_reassign:', incoming.id, ']%')
LEFT JOIN __SOURCE_SCHEMA__.payment_report_reassignments outgoing ON outgoing.from_report_id = r.id
       AND outgoing.campus_id = i.campus_id AND outgoing.from_student_id = i.student_id AND outgoing.from_invoice_id = i.id
       AND p.Amount < 0 AND ABS(p.Amount) = outgoing.amount AND p.Method = 'void'
       AND p.Note LIKE CONCAT('[receipt_reassign:', outgoing.id, ']%');

CREATE SQL SECURITY DEFINER VIEW alltrue_diagnostics.daan_tuition_reports AS
SELECT r.id, d.campus_id, s.id AS student_id, c.id AS contract_id, i.id AS invoice_id, p.id AS payment_id,
       (r.StudentClassID IS NOT NULL AND c.id IS NULL) AS contract_link_uncertain,
       (r.InvoiceID IS NOT NULL AND i.id IS NULL) AS invoice_link_uncertain,
       (r.payment_id IS NOT NULL AND p.id IS NULL) AS payment_link_uncertain,
       CASE WHEN r.billing_period REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$' THEN r.billing_period END AS billing_period,
       r.payment_date, r.reported_amount AS amount, r.created_at, r.updated_at,
       r.confirmed_at, r.voided_at,
       CASE r.status WHEN 'pending' THEN 'pending' WHEN 'confirmed' THEN 'confirmed'
            WHEN 'rejected' THEN 'rejected' WHEN 'voided' THEN 'voided' ELSE 'other' END AS status
FROM __SOURCE_SCHEMA__.payment_reports r
JOIN __SOURCE_SCHEMA__.Student s ON s.id = r.StudentID
JOIN alltrue_diagnostics.daan_tuition_scope d ON d.campus_id = s.CampusID
LEFT JOIN alltrue_diagnostics.daan_tuition_contracts c ON c.id = r.StudentClassID AND c.student_id = s.id
LEFT JOIN alltrue_diagnostics.daan_tuition_invoices i ON i.id = r.InvoiceID AND i.student_id = s.id
LEFT JOIN alltrue_diagnostics.daan_tuition_payments p ON p.id = r.payment_id AND p.student_id = s.id
WHERE i.id IS NOT NULL OR p.id IS NOT NULL
   OR r.payment_date < d.cutoff_exclusive
   OR (r.billing_period REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$' AND r.billing_period < LEFT(d.cutoff_exclusive, 7))
   OR (r.payment_date IS NULL AND (r.created_at < d.cutoff_exclusive OR r.created_at IS NULL));
