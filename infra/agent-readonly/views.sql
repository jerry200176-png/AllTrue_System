-- REVIEW TEMPLATE ONLY: replace __SOURCE_SCHEMA__ with a verified schema identifier.
-- An approved privileged operator creates the diagnostic schema and these views.
-- Never grant the agent SELECT on source tables or mysql.*, and never use OR REPLACE.
CREATE SQL SECURITY DEFINER VIEW alltrue_diagnostics.contracts AS
SELECT c.ID AS class_id, c.StudentID AS student_id, s.CampusID AS campus_id,
       c.SubjectID AS subject_id, c.TeacherID AS teacher_id,
       c.StartDate AS start_date, c.EndDate AS end_date,
       CASE c.ScheduleMode WHEN 'count' THEN 'count' WHEN 'date' THEN 'date' ELSE 'other' END AS schedule_mode,
       CASE c.ClassType WHEN 'one_on_one' THEN 'one_on_one' WHEN 'one_on_two' THEN 'one_on_two'
            WHEN 'one_on_three' THEN 'one_on_three' WHEN 'tutoring' THEN 'tutoring' WHEN 'trial' THEN 'trial' ELSE 'other' END AS class_type,
       c.SessionCount AS purchased_units, c.SessionDuration AS session_duration,
       c.UsedSessions AS stored_used_units, c.RemainingSessions AS stored_remaining_units,
       c.PurchasedMinutes AS purchased_minutes, c.RemainingMinutes AS remaining_minutes,
       c.PackageID AS package_id, c.Stop AS stopped,
       c.Charge AS charge, c.Paid AS paid, c.MDate AS modified_at
FROM __SOURCE_SCHEMA__.StudentClass c LEFT JOIN __SOURCE_SCHEMA__.Student s ON s.id = c.StudentID;

CREATE SQL SECURITY DEFINER VIEW alltrue_diagnostics.sessions AS
SELECT x.id AS session_id, x.StudentClassID AS class_id, c.StudentID AS student_id,
       s.CampusID AS campus_id, x.SubjectID AS subject_id,
       x.SessionDate AS session_date, x.StartTime AS start_time, x.EndTime AS end_time,
       x.Status AS status, x.OriginalSessionDate AS original_session_date,
       x.OriginalStartTimeHM AS original_start_time, x.IsContractException AS contract_exception,
       x.created_at, x.updated_at
FROM __SOURCE_SCHEMA__.ClassSession x
LEFT JOIN __SOURCE_SCHEMA__.StudentClass c ON c.ID = x.StudentClassID
LEFT JOIN __SOURCE_SCHEMA__.Student s ON s.id = c.StudentID;

CREATE SQL SECURITY DEFINER VIEW alltrue_diagnostics.attendance AS
SELECT a.id AS attendance_id, a.StudentClassID AS class_id, a.ClassSessionID AS session_id,
       a.StudentID AS student_id, a.CampusID AS recorded_campus_id, s.CampusID AS campus_id,
       a.TeacherID AS teacher_id, a.PersonType AS person_type, a.Hours AS hours,
       a.SignInDT AS signed_in_at, a.SignOutDT AS signed_out_at,
       a.Status AS status, a.SessionDeducted AS session_deducted, a.VoidedAt AS voided_at
FROM __SOURCE_SCHEMA__.StudentSingIn a LEFT JOIN __SOURCE_SCHEMA__.Student s ON s.id = a.StudentID;

CREATE SQL SECURITY DEFINER VIEW alltrue_diagnostics.assessments AS
SELECT a.id AS assessment_id, a.StudentClassID AS class_id, a.ClassSessionID AS session_id,
       c.StudentID AS student_id, s.CampusID AS campus_id, a.TeacherID AS teacher_id,
       a.SessionDate AS session_date, a.StartTime AS start_time, a.EndTime AS end_time,
       a.Status AS status, a.SessionDeducted AS session_deducted,
       a.ExcludeFromSubjectCount AS excluded_from_subject_count,
       a.ApprovedAt AS approved_at, a.VoidedAt AS voided_at, a.created_at, a.updated_at
FROM __SOURCE_SCHEMA__.LearningRecord a
LEFT JOIN __SOURCE_SCHEMA__.StudentClass c ON c.ID = a.StudentClassID
LEFT JOIN __SOURCE_SCHEMA__.Student s ON s.id = c.StudentID;

CREATE SQL SECURITY DEFINER VIEW alltrue_diagnostics.deductions AS
SELECT l.id AS ledger_id, l.student_class_id AS class_id, l.class_session_id AS session_id,
       c.StudentID AS student_id, s.CampusID AS campus_id,
       CASE l.event_type WHEN 'deduct' THEN 'deduct' WHEN 'reverse' THEN 'reverse' ELSE 'other' END AS event_type,
       l.minutes, l.created_at
FROM __SOURCE_SCHEMA__.session_deduction_ledger l
LEFT JOIN __SOURCE_SCHEMA__.StudentClass c ON c.ID = l.student_class_id
LEFT JOIN __SOURCE_SCHEMA__.Student s ON s.id = c.StudentID;

CREATE SQL SECURITY DEFINER VIEW alltrue_diagnostics.packages AS
SELECT id AS package_id, student_id, campus_id,
       CASE billing_mode WHEN 'count' THEN 'count' WHEN 'date' THEN 'date' ELSE 'other' END AS billing_mode,
       total_sessions, remaining_sessions, used_sessions,
       CASE class_type WHEN 'one_on_one' THEN 'one_on_one' WHEN 'one_on_two' THEN 'one_on_two'
            WHEN 'one_on_three' THEN 'one_on_three' WHEN 'tutoring' THEN 'tutoring' WHEN 'trial' THEN 'trial' ELSE 'other' END AS class_type,
       paid, paid_at, stop, enabled
FROM __SOURCE_SCHEMA__.course_packages;

CREATE SQL SECURITY DEFINER VIEW alltrue_diagnostics.package_deductions AS
SELECT l.id AS ledger_id, l.package_id, l.student_class_id AS class_id,
       l.class_session_id AS session_id, p.student_id, p.campus_id, l.delta, l.created_at
FROM __SOURCE_SCHEMA__.package_session_ledger l
LEFT JOIN __SOURCE_SCHEMA__.course_packages p ON p.id = l.package_id;

CREATE SQL SECURITY DEFINER VIEW alltrue_diagnostics.invoices AS
SELECT i.id AS invoice_id, i.StudentID AS student_id, s.CampusID AS campus_id,
       i.StudentClassID AS class_id, i.IssueDate AS issue_date, i.DueDate AS due_date,
       i.TotalAmount AS total_amount, i.PaidAmount AS stored_paid_amount, i.Status AS status,
       i.billing_period, i.ScheduleModeAtIssue AS schedule_mode_at_issue, i.zero_amount_reason,
       i.created_at, i.updated_at
FROM __SOURCE_SCHEMA__.Invoice i LEFT JOIN __SOURCE_SCHEMA__.Student s ON s.id = i.StudentID;

CREATE SQL SECURITY DEFINER VIEW alltrue_diagnostics.invoice_items AS
SELECT a.id AS item_id, a.InvoiceID AS invoice_id, a.StudentClassID AS class_id,
       c.StudentID AS student_id, s.CampusID AS campus_id,
       a.Amount AS amount, a.PeriodStart AS period_start, a.PeriodEnd AS period_end
FROM __SOURCE_SCHEMA__.InvoiceItem a
LEFT JOIN __SOURCE_SCHEMA__.StudentClass c ON c.ID = a.StudentClassID
LEFT JOIN __SOURCE_SCHEMA__.Student s ON s.id = c.StudentID;

CREATE SQL SECURITY DEFINER VIEW alltrue_diagnostics.payments AS
SELECT p.id AS payment_id, p.InvoiceID AS invoice_id, p.payment_report_id AS report_id,
       i.StudentID AS student_id, s.CampusID AS campus_id, i.StudentClassID AS class_id,
       p.Amount AS amount, p.PaidAt AS paid_at,
       CASE p.Method WHEN 'cash' THEN 'cash' WHEN 'transfer' THEN 'transfer' WHEN 'void' THEN 'void'
            WHEN 'refund' THEN 'refund' WHEN 'transfer_in' THEN 'transfer_in' WHEN 'transfer_out' THEN 'transfer_out' ELSE 'other' END AS method,
       p.created_at, p.updated_at
FROM __SOURCE_SCHEMA__.Payment p
LEFT JOIN __SOURCE_SCHEMA__.Invoice i ON i.id = p.InvoiceID
LEFT JOIN __SOURCE_SCHEMA__.Student s ON s.id = i.StudentID;

CREATE SQL SECURITY DEFINER VIEW alltrue_diagnostics.receipts AS
SELECT r.id AS report_id, r.StudentID AS student_id, s.CampusID AS campus_id,
       r.StudentClassID AS class_id, r.InvoiceID AS invoice_id, r.payment_id,
       r.billing_period, r.payment_date, r.payment_method, r.reported_amount, r.status,
       r.confirmed_at, r.voided_at, r.created_at, r.updated_at
FROM __SOURCE_SCHEMA__.payment_reports r LEFT JOIN __SOURCE_SCHEMA__.Student s ON s.id = r.StudentID;
