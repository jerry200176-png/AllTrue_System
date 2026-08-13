#!/usr/bin/env bash
# Read-only production diagnosis for the two allowlisted Muzha entitlement-transfer cases.
set -euo pipefail

ENV_FILE="${ENV_FILE:-/home/admin/backend/.env}"
DB_USER=$(grep '^DB_USERNAME=' "$ENV_FILE" | cut -d= -f2-)
DB_PASS=$(grep '^DB_PASSWORD=' "$ENV_FILE" | cut -d= -f2-)
DB_NAME=$(grep '^DB_DATABASE=' "$ENV_FILE" | cut -d= -f2-)
M=(mysql -h 127.0.0.1 -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -B)

echo "=== entitlement capacity diagnosis generated=$(date -Iseconds) ==="
echo "ALLOWLIST student_ids=374,373 batches=1681,1682,2649,2606 sessions=23157,27156"

echo "--- exact batch identity and subject ---"
"${M[@]}" -e "
SELECT CONCAT_WS('|',sc.ID,sc.StudentID,s.name,s.CampusID,IFNULL(sc.SubjectID,'null'),IFNULL(sub.Subject_Name,'null'),
 sc.TeacherID,sc.Stop,sc.ScheduleMode,sc.SessionCount,IFNULL(sc.UsedSessions,'null'),
 IFNULL(sc.RemainingSessions,'null'),IFNULL(sc.Paid,'null'),IFNULL(sc.PackageID,'null'))
FROM StudentClass sc
JOIN Student s ON s.id=sc.StudentID
LEFT JOIN Subject sub ON sub.id=sc.SubjectID
WHERE sc.ID IN (1681,1682,2649,2606)
ORDER BY sc.StudentID,sc.ID;"

echo "--- every capacity-committed session with linked-record evidence ---"
"${M[@]}" -e "
SELECT CONCAT_WS('|',cs.StudentClassID,cs.id,sc.StudentID,s.name,sc.SubjectID,
 IFNULL(cs.SubjectID,'inherit'),IFNULL(COALESCE(css.Subject_Name,bsub.Subject_Name),'?'),
 DATE(cs.SessionDate),LEFT(cs.StartTime,5),LEFT(cs.EndTime,5),LOWER(cs.Status),
 CASE
   WHEN LOWER(cs.Status)='scheduled' THEN 'reservation_commitment'
   WHEN LOWER(IFNULL(cs.Note,'')) REGEXP 'leave|makeup|補課|請假'
     OR EXISTS(SELECT 1 FROM StudentSingIn sx WHERE sx.ClassSessionID=cs.id AND LOWER(sx.Status)='leave')
     THEN 'leave_makeup_evidence'
   ELSE 'consumed_session'
 END,
 (SELECT COUNT(*) FROM LearningRecord lr WHERE lr.ClassSessionID=cs.id),
 IFNULL((SELECT GROUP_CONCAT(CONCAT(lr.id,':',lr.Status,':class=',lr.StudentClassID) ORDER BY lr.id SEPARATOR ',') FROM LearningRecord lr WHERE lr.ClassSessionID=cs.id),'none'),
 (SELECT COUNT(*) FROM StudentSingIn si WHERE si.ClassSessionID=cs.id AND si.VoidedAt IS NULL),
 IFNULL((SELECT GROUP_CONCAT(CONCAT(si.id,':',si.Status,':deduct=',IFNULL(si.SessionDeducted,'null'),':class=',si.StudentClassID) ORDER BY si.id SEPARATOR ',') FROM StudentSingIn si WHERE si.ClassSessionID=cs.id),'none'),
 (SELECT COUNT(*) FROM session_deduction_ledger dl WHERE dl.class_session_id=cs.id),
 IFNULL((SELECT GROUP_CONCAT(CONCAT(dl.id,':',dl.event_type,':',dl.source,':class=',dl.student_class_id) ORDER BY dl.id SEPARATOR ',') FROM session_deduction_ledger dl WHERE dl.class_session_id=cs.id),'none'),
 LEFT(REPLACE(REPLACE(IFNULL(cs.Note,''),'\n',' '),'\r',' '),160))
FROM ClassSession cs
JOIN StudentClass sc ON sc.ID=cs.StudentClassID
JOIN Student s ON s.id=sc.StudentID
LEFT JOIN Subject bsub ON bsub.id=sc.SubjectID
LEFT JOIN Subject css ON css.id=cs.SubjectID
WHERE cs.StudentClassID IN (1681,1682,2649,2606)
  AND LOWER(cs.Status) IN ('scheduled','attended','completed','late')
ORDER BY cs.StudentClassID,cs.SessionDate,cs.StartTime,cs.id;"

echo "--- persisted counters and canonical counter components ---"
"${M[@]}" -e "
SELECT CONCAT_WS('|',sc.ID,sc.SessionCount,IFNULL(sc.UsedSessions,'null'),IFNULL(sc.RemainingSessions,'null'),
 (SELECT COUNT(*) FROM ClassSession cs WHERE cs.StudentClassID=sc.ID AND LOWER(cs.Status) IN ('attended','completed','late')),
 (SELECT COUNT(DISTINCT COALESCE(NULLIF(si.ClassSessionID,0),si.id)) FROM StudentSingIn si WHERE si.StudentClassID=sc.ID AND si.VoidedAt IS NULL AND si.SessionDeducted=1),
 (SELECT COUNT(*) FROM ClassSession cs WHERE cs.StudentClassID=sc.ID AND LOWER(cs.Status) IN ('scheduled','attended','completed','late')),
 (SELECT COUNT(*) FROM ClassSession cs WHERE cs.StudentClassID=sc.ID AND LOWER(cs.Status)='scheduled'),
 (SELECT COUNT(DISTINCT CONCAT(DATE(lr.SessionDate),'|',IFNULL(LEFT(lr.StartTime,5),''))) FROM LearningRecord lr WHERE lr.StudentClassID=sc.ID AND lr.Status='approved' AND (lr.ClassSessionID IS NULL OR lr.ClassSessionID<=0) AND lr.SessionDate IS NOT NULL),
 (SELECT COALESCE(SUM(CASE WHEN dl.event_type='deduct' THEN 1 WHEN dl.event_type='reverse' THEN -1 ELSE 0 END),0) FROM session_deduction_ledger dl WHERE dl.student_class_id=sc.ID AND dl.source IN ('attendance','retro_leave','status_adjust')))
FROM StudentClass sc WHERE sc.ID IN (1681,1682,2649,2606)
ORDER BY sc.StudentID,sc.ID;"

echo "--- scheduled commitments not yet represented as used/deducted ---"
"${M[@]}" -e "
SELECT CONCAT_WS('|',cs.StudentClassID,cs.id,DATE(cs.SessionDate),LEFT(cs.StartTime,5),LOWER(cs.Status),
 (SELECT COUNT(*) FROM StudentSingIn si WHERE si.ClassSessionID=cs.id AND si.VoidedAt IS NULL AND si.SessionDeducted=1),
 (SELECT COUNT(*) FROM session_deduction_ledger dl WHERE dl.class_session_id=cs.id AND dl.event_type='deduct'))
FROM ClassSession cs
WHERE cs.StudentClassID IN (1681,1682,2649,2606) AND LOWER(cs.Status)='scheduled'
ORDER BY cs.StudentClassID,cs.SessionDate,cs.StartTime;"

echo "--- active same-subject candidate batches (proposal evidence only) ---"
"${M[@]}" -e "
SELECT CONCAT_WS('|',candidate.ID,candidate.StudentID,IFNULL(candidate.SubjectID,'null'),IFNULL(sub.Subject_Name,'null'),
 candidate.SessionCount,IFNULL(candidate.UsedSessions,'null'),IFNULL(candidate.RemainingSessions,'null'),candidate.Stop,
 (SELECT COUNT(*) FROM ClassSession cs WHERE cs.StudentClassID=candidate.ID AND LOWER(cs.Status) IN ('scheduled','attended','completed','late')))
FROM StudentClass candidate
LEFT JOIN Subject sub ON sub.id=candidate.SubjectID
WHERE candidate.StudentID IN (373,374)
  AND candidate.Stop=0 AND candidate.ScheduleMode='count'
  AND candidate.SubjectID IN (SELECT SubjectID FROM StudentClass WHERE ID IN (1681,1682))
ORDER BY candidate.StudentID,candidate.SubjectID,candidate.ID;"

echo "--- every contract with subject and financial evidence ---"
"${M[@]}" -e "
SELECT CONCAT_WS('|',sc.StudentID,s.name,sc.ID,IFNULL(sc.SubjectID,'null'),IFNULL(sub.Subject_Name,'null'),
 sc.TeacherID,sc.Stop,sc.ScheduleMode,sc.SessionCount,IFNULL(sc.UsedSessions,'null'),
 IFNULL(sc.RemainingSessions,'null'),IFNULL(sc.Charge,'null'),IFNULL(sc.Paid,'null'),
 (SELECT COUNT(*) FROM ClassSession cs WHERE cs.StudentClassID=sc.ID AND LOWER(cs.Status) IN ('scheduled','attended','completed','late')),
 IFNULL((SELECT GROUP_CONCAT(CONCAT(i.id,':',i.Status,':total=',i.TotalAmount,':paid=',i.PaidAmount,':date=',i.IssueDate) ORDER BY i.id SEPARATOR ',') FROM Invoice i WHERE i.StudentClassID=sc.ID),'none'),
 IFNULL((SELECT GROUP_CONCAT(CONCAT(p.id,':amount=',p.Amount,':date=',DATE(p.PaidAt),':method=',IFNULL(p.Method,'null')) ORDER BY p.id SEPARATOR ',') FROM Payment p JOIN Invoice i ON i.id=p.InvoiceID WHERE i.StudentClassID=sc.ID),'none'))
FROM StudentClass sc
JOIN Student s ON s.id=sc.StudentID
LEFT JOIN Subject sub ON sub.id=sc.SubjectID
WHERE sc.StudentID IN (373,374)
ORDER BY sc.StudentID,sc.ID;"

echo "--- all same-Chinese-subject candidates including stopped/full batches ---"
"${M[@]}" -e "
SELECT CONCAT_WS('|',sc.StudentID,sc.ID,sc.SubjectID,IFNULL(sub.Subject_Name,'null'),sc.Stop,
 sc.ScheduleMode,sc.SessionCount,IFNULL(sc.UsedSessions,'null'),IFNULL(sc.RemainingSessions,'null'),
 (SELECT COUNT(*) FROM ClassSession cs WHERE cs.StudentClassID=sc.ID AND LOWER(cs.Status) IN ('scheduled','attended','completed','late')),
 IFNULL((SELECT GROUP_CONCAT(CONCAT(i.id,':',i.Status,':total=',i.TotalAmount,':paid=',i.PaidAmount) ORDER BY i.id SEPARATOR ',') FROM Invoice i WHERE i.StudentClassID=sc.ID),'none'))
FROM StudentClass sc
LEFT JOIN Subject sub ON sub.id=sc.SubjectID
JOIN (
 SELECT StudentID,SubjectID
 FROM StudentClass
 WHERE ID IN (1681,1682)
) source_subject
 ON source_subject.StudentID=sc.StudentID AND source_subject.SubjectID=sc.SubjectID
WHERE sc.StudentID IN (373,374)
ORDER BY sc.StudentID,sc.ID;"

echo "--- exact transfer sessions remain on source ---"
"${M[@]}" -e "
SELECT CONCAT_WS('|',cs.id,cs.StudentClassID,sc.StudentID,sc.SubjectID,IFNULL(cs.SubjectID,'inherit'),
 DATE(cs.SessionDate),LEFT(cs.StartTime,5),LOWER(cs.Status))
FROM ClassSession cs JOIN StudentClass sc ON sc.ID=cs.StudentClassID
WHERE cs.id IN (23157,27156);"
echo "=== END READ-ONLY DIAGNOSIS ==="
