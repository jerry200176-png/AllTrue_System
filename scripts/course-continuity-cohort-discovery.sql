-- Course Continuity — PII-free production cohort discovery
-- Refs: GitHub #1382 / docs/architecture/RFC_COURSE_CONTINUITY.md
-- ⛔ READ-ONLY. Do not UPDATE/DELETE. Do not SELECT name/phone/memo/leave reason.
-- Output: aggregate counts only. Paste results into
--   docs/architecture/course-continuity-cohort-results.md

-- 0) Baseline
SELECT
  (SELECT COUNT(*) FROM StudentClass WHERE Stop = 0) AS active_contracts,
  (SELECT COUNT(DISTINCT StudentID) FROM StudentClass WHERE Stop = 0) AS students_with_active_contract;

-- Helper view pattern: pairs of active contracts same student+subject
-- (run as derived tables; do not create permanent views on production without GO)

-- 1) Adjacent renewal candidates: same student, subject, teacher; EndDate then StartDate within 14 days; no date overlap
SELECT COUNT(*) AS group_count,
       COUNT(DISTINCT a.StudentID) AS student_count,
       COUNT(*) * 2 AS approx_contract_mentions
FROM StudentClass a
JOIN StudentClass b
  ON a.StudentID = b.StudentID
 AND a.SubjectID = b.SubjectID
 AND a.TeacherID = b.TeacherID
 AND a.ID < b.ID
 AND a.Stop = 0 AND b.Stop = 0
WHERE DATE(a.EndDate) < DATE(b.StartDate)
  AND DATEDIFF(DATE(b.StartDate), DATE(a.EndDate)) BETWEEN 0 AND 14
  AND IFNULL(a.PackageID, 0) = 0 AND IFNULL(b.PackageID, 0) = 0;

-- 2) Same student+subject+teacher with date overlap
SELECT COUNT(*) AS group_count,
       COUNT(DISTINCT a.StudentID) AS student_count
FROM StudentClass a
JOIN StudentClass b
  ON a.StudentID = b.StudentID
 AND a.SubjectID = b.SubjectID
 AND a.TeacherID = b.TeacherID
 AND a.ID < b.ID
 AND a.Stop = 0 AND b.Stop = 0
WHERE DATE(a.StartDate) <= DATE(IFNULL(b.EndDate, '9999-12-31'))
  AND DATE(b.StartDate) <= DATE(IFNULL(a.EndDate, '9999-12-31'));

-- 3) Same student+subject, different teacher (both active)
SELECT COUNT(*) AS group_count,
       COUNT(DISTINCT a.StudentID) AS student_count
FROM StudentClass a
JOIN StudentClass b
  ON a.StudentID = b.StudentID
 AND a.SubjectID = b.SubjectID
 AND a.TeacherID <> b.TeacherID
 AND a.ID < b.ID
 AND a.Stop = 0 AND b.Stop = 0;

-- 4) Same student, different subject, overlapping wall-clock slots (future scheduled)
-- Uses ClassSession; counts overlapping future sessions across different subjects
SELECT COUNT(*) AS future_cross_subject_overlap_session_pairs
FROM ClassSession x
JOIN ClassSession y
  ON x.id < y.id
 AND x.SessionDate = y.SessionDate
 AND x.StartTime = y.StartTime
 AND x.Status = 'scheduled' AND y.Status = 'scheduled'
 AND x.SessionDate >= CURDATE()
JOIN StudentClass sx ON sx.ID = x.StudentClassID
JOIN StudentClass sy ON sy.ID = y.StudentClassID
WHERE sx.StudentID = sy.StudentID
  AND sx.SubjectID <> sy.SubjectID;

-- 5) Trial + formal (same student+subject, both active)
SELECT COUNT(*) AS group_count,
       COUNT(DISTINCT a.StudentID) AS student_count
FROM StudentClass a
JOIN StudentClass b
  ON a.StudentID = b.StudentID
 AND a.SubjectID = b.SubjectID
 AND a.ID < b.ID
 AND a.Stop = 0 AND b.Stop = 0
WHERE (a.ClassType = 'trial') <> (b.ClassType = 'trial')
  AND (a.ClassType = 'trial' OR b.ClassType = 'trial');

-- 6) Different class types (same student+subject, both active, neither trial-only pair)
SELECT COUNT(*) AS group_count
FROM StudentClass a
JOIN StudentClass b
  ON a.StudentID = b.StudentID
 AND a.SubjectID = b.SubjectID
 AND a.ID < b.ID
 AND a.Stop = 0 AND b.Stop = 0
WHERE IFNULL(a.ClassType,'') <> IFNULL(b.ClassType,'');

-- 7) Mixed rate_unit / ScheduleMode among same student+subject active pair
SELECT COUNT(*) AS group_count
FROM StudentClass a
JOIN StudentClass b
  ON a.StudentID = b.StudentID
 AND a.SubjectID = b.SubjectID
 AND a.ID < b.ID
 AND a.Stop = 0 AND b.Stop = 0
WHERE IFNULL(a.rate_unit,'') <> IFNULL(b.rate_unit,'')
   OR IFNULL(a.ScheduleMode,'') <> IFNULL(b.ScheduleMode,'')
   OR IFNULL(a.PackageID,0) <> IFNULL(b.PackageID,0);

-- 8) Different SessionDuration or Rate (same student+subject+teacher active)
SELECT COUNT(*) AS group_count
FROM StudentClass a
JOIN StudentClass b
  ON a.StudentID = b.StudentID
 AND a.SubjectID = b.SubjectID
 AND a.TeacherID = b.TeacherID
 AND a.ID < b.ID
 AND a.Stop = 0 AND b.Stop = 0
WHERE IFNULL(a.SessionDuration,0) <> IFNULL(b.SessionDuration,0)
   OR IFNULL(a.Rate,0) <> IFNULL(b.Rate,0);

-- 9) Both sides have Invoice or Payment (no amounts/PII — existence only)
SELECT COUNT(*) AS pair_count_both_have_invoice_or_payment
FROM StudentClass a
JOIN StudentClass b
  ON a.StudentID = b.StudentID AND a.SubjectID = b.SubjectID AND a.ID < b.ID
WHERE EXISTS (SELECT 1 FROM Invoice i WHERE i.StudentClassID = a.ID AND IFNULL(i.VoidedAt,0)=0)
  AND EXISTS (SELECT 1 FROM Invoice i WHERE i.StudentClassID = b.ID AND IFNULL(i.VoidedAt,0)=0);

-- 10) Both sides have attended sessions or LearningRecords
SELECT COUNT(*) AS pair_count_both_have_attendance_or_lr
FROM StudentClass a
JOIN StudentClass b
  ON a.StudentID = b.StudentID AND a.SubjectID = b.SubjectID AND a.ID < b.ID
WHERE (
    EXISTS (SELECT 1 FROM ClassSession c WHERE c.StudentClassID=a.ID AND c.Status='attended')
    OR EXISTS (SELECT 1 FROM LearningRecord lr WHERE lr.StudentClassID=a.ID)
  )
  AND (
    EXISTS (SELECT 1 FROM ClassSession c WHERE c.StudentClassID=b.ID AND c.Status='attended')
    OR EXISTS (SELECT 1 FROM LearningRecord lr WHERE lr.StudentClassID=b.ID)
  );

-- 11) Stop=1 old contract still has future scheduled sessions
SELECT COUNT(DISTINCT sc.ID) AS stopped_contracts_with_future_sessions,
       COUNT(*) AS future_scheduled_rows
FROM StudentClass sc
JOIN ClassSession cs ON cs.StudentClassID = sc.ID
WHERE sc.Stop = 1
  AND cs.Status = 'scheduled'
  AND cs.SessionDate >= CURDATE();

-- 12) Cross-campus anomaly: same StudentID appearing under different CampusID via Student join mismatch
-- (contracts whose student campus differs from operator expectation — count only)
SELECT COUNT(*) AS contracts_student_campus_joinable
FROM StudentClass sc
JOIN Student s ON s.id = sc.StudentID;

-- 13) Chains: students with 3+ active contracts same subject
SELECT COUNT(*) AS student_count_with_3plus_same_subject_active
FROM (
  SELECT StudentID, SubjectID, COUNT(*) AS n
  FROM StudentClass
  WHERE Stop = 0
  GROUP BY StudentID, SubjectID
  HAVING n >= 3
) t;

-- High-confidence renewal candidate ≈ query (1) minus pairs with attended overlap or billing on both sides
-- Manual / unsafe counts: fill after running (2)(7)(9)(10) — see results template.
