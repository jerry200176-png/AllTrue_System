#!/usr/bin/env bash
# Read-only Pi diagnose: who scheduled a given student's ClassSession and who marked it attended.
# Usage: DATE=2026-07-28 CAMPUS_ID=9 STUDENT_NAME=黃奕凱 TEACHER_NAME=張翔 bash scripts/diagnose-student-session.sh
set -euo pipefail
DATE="${DATE:-$(date +%Y-%m-%d)}"; CAMPUS_ID="${CAMPUS_ID:-9}"
STUDENT_NAME="${STUDENT_NAME:?STUDENT_NAME required}"; TEACHER_NAME="${TEACHER_NAME:-}"
ENV_FILE="${ENV_FILE:-/home/admin/backend/.env}"
DB_USER=$(grep '^DB_USERNAME=' "$ENV_FILE" | cut -d= -f2-)
DB_PASS=$(grep '^DB_PASSWORD=' "$ENV_FILE" | cut -d= -f2-)
DB_NAME=$(grep '^DB_DATABASE=' "$ENV_FILE" | cut -d= -f2-)
M=(mysql -h 127.0.0.1 -u "$DB_USER" -p"${DB_PASS}" "$DB_NAME" -N -B)
SN=$(printf "%s" "$STUDENT_NAME" | sed "s/'/\\\\'/g")

echo "=== diagnose STUDENT=$STUDENT_NAME DATE=$DATE CAMPUS=$CAMPUS_ID generated=$(date -Iseconds) ==="

echo "--- student (exact) ---"
"${M[@]}" -e "SELECT id,name,CampusID FROM Student WHERE name='$SN' AND CampusID=$CAMPUS_ID;"

echo "--- student (fuzzy, any campus, in case of name/campus mismatch) ---"
LIKE_PART=$(printf "%s" "$STUDENT_NAME" | cut -c1-3)
"${M[@]}" -e "SELECT id,name,CampusID FROM Student WHERE name LIKE CONCAT('%','$LIKE_PART','%') LIMIT 20;"

echo "--- teacher (if provided) ---"
if [ -n "$TEACHER_NAME" ]; then
  TN=$(printf "%s" "$TEACHER_NAME" | sed "s/'/\\\\'/g")
  "${M[@]}" -e "SELECT id,Name,LoginName FROM User WHERE Name='$TN' LIMIT 5;"
  echo "--- teacher occupancy UserCampus (ids only) ---"
  "${M[@]}" -e "SELECT CONCAT_WS('|','uc',uc.UserID,uc.CampusID,uc.Approved) FROM UserCampus uc JOIN User u ON u.id=uc.UserID WHERE u.Name='$TN' ORDER BY uc.CampusID;"
  echo "--- teacher occupancy ClassSession on DATE (ids only, no names) ---"
  "${M[@]}" -e "SELECT CONCAT_WS('|','cs',cs.id,cs.StudentClassID,sc.StudentID,st.CampusID,IFNULL(sc.ClassType,''),cs.Status,SUBSTRING(cs.StartTime,1,5),SUBSTRING(cs.EndTime,1,5),sc.Stop) FROM ClassSession cs JOIN StudentClass sc ON sc.ID=cs.StudentClassID JOIN Student st ON st.id=sc.StudentID JOIN User u ON u.id=sc.TeacherID WHERE u.Name='$TN' AND cs.SessionDate='$DATE' ORDER BY cs.StartTime, cs.id;"
  echo "--- teacher occupancy schedules on DATE (ids only, no names) ---"
  "${M[@]}" -e "SELECT CONCAT_WS('|','sch',s.id,s.branch_id,s.student_id,IFNULL(s.student_course_id,'null'),s.status,IFNULL(s.type,''),IFNULL(s.class_type,''),SUBSTRING(s.start_time,1,5),SUBSTRING(s.end_time,1,5),IFNULL(s.original_schedule_id,'null')) FROM schedules s JOIN User u ON u.id=s.teacher_id WHERE u.Name='$TN' AND DATE(s.schedule_date)='$DATE' ORDER BY s.start_time, s.id;"
fi

echo "--- StudentClass rows for this student (all active courses) ---"
"${M[@]}" -e "SELECT CONCAT_WS('|',sc.ID,sc.TeacherID,sc.Stop,sc.ScheduleMode,sc.SessionCount,IFNULL(sc.UsedSessions,'null'),IFNULL(sc.RemainingSessions,'null'),IFNULL(sc.Rate,'null'),IFNULL(sc.rate_unit,'null'),IFNULL(sc.Charge,'null'),IFNULL(sc.Paid,'null'),s.CampusID)
FROM StudentClass sc JOIN Student s ON s.id=sc.StudentID
WHERE s.name='$SN' AND s.CampusID=$CAMPUS_ID;"

echo "--- ClassSession rows on/near target date ---"
"${M[@]}" -e "SELECT CONCAT_WS('|',cs.id,cs.StudentClassID,sc.TeacherID,cs.SessionDate,SUBSTRING(cs.StartTime,1,5),SUBSTRING(cs.EndTime,1,5),cs.Status,LEFT(IFNULL(cs.Note,''),120),IFNULL(cs.created_at,''),IFNULL(cs.updated_at,''))
FROM ClassSession cs JOIN StudentClass sc ON sc.ID=cs.StudentClassID JOIN Student s ON s.id=sc.StudentID
WHERE s.name='$SN' AND s.CampusID=$CAMPUS_ID
 AND cs.SessionDate BETWEEN DATE_SUB('$DATE', INTERVAL 21 DAY) AND DATE_ADD('$DATE', INTERVAL 7 DAY)
ORDER BY cs.SessionDate, cs.StartTime;"

echo "--- schedule_audit_logs for those ClassSession ids (who created/changed the schedule row) ---"
"${M[@]}" -e "
SELECT CONCAT_WS('|',sal.id,sal.session_id,sal.action_type,sal.description,sal.operator_id,IFNULL(u.Name,'?'),sal.branch_id,sal.created_at)
FROM schedule_audit_logs sal
LEFT JOIN User u ON u.id=sal.operator_id
WHERE sal.session_id IN (
  SELECT cs.id FROM ClassSession cs JOIN StudentClass sc ON sc.ID=cs.StudentClassID JOIN Student s ON s.id=sc.StudentID
  WHERE s.name='$SN' AND s.CampusID=$CAMPUS_ID
   AND cs.SessionDate BETWEEN DATE_SUB('$DATE', INTERVAL 21 DAY) AND DATE_ADD('$DATE', INTERVAL 7 DAY)
)
ORDER BY sal.created_at;"

echo "--- LearningRecord (evaluation/who filled it) for target date sessions ---"
"${M[@]}" -e "
SELECT CONCAT_WS('|',lr.id,lr.ClassSessionID,lr.TeacherID,IFNULL(lr.CreatedByUserID,'null'),IFNULL(u.Name,'?'),lr.Status,IFNULL(lr.VoidedAt,''),lr.created_at,lr.updated_at)
FROM LearningRecord lr
LEFT JOIN User u ON u.id=lr.CreatedByUserID
WHERE lr.ClassSessionID IN (
  SELECT cs.id FROM ClassSession cs JOIN StudentClass sc ON sc.ID=cs.StudentClassID JOIN Student s ON s.id=sc.StudentID
  WHERE s.name='$SN' AND s.CampusID=$CAMPUS_ID AND cs.SessionDate='$DATE'
)
ORDER BY lr.created_at;"

echo "--- StudentSignIn (attendance / 點名, who recorded it) for target date sessions ---"
"${M[@]}" -e "
SELECT CONCAT_WS('|',si.id,si.ClassSessionID,si.StudentClassID,si.TeacherID,IFNULL(si.RecordedByUserID,'null'),IFNULL(u.Name,'?'),si.Status,IFNULL(si.SignInDT,''),IFNULL(si.VoidedAt,''))
FROM StudentSingIn si
LEFT JOIN User u ON u.id=si.RecordedByUserID
WHERE si.ClassSessionID IN (
  SELECT cs.id FROM ClassSession cs JOIN StudentClass sc ON sc.ID=cs.StudentClassID JOIN Student s ON s.id=sc.StudentID
  WHERE s.name='$SN' AND s.CampusID=$CAMPUS_ID AND cs.SessionDate='$DATE'
)
ORDER BY si.id;"

echo "--- Invoice rows linked to this student's contracts ---"
"${M[@]}" -e "
SELECT CONCAT_WS('|',i.id,i.StudentClassID,i.IssueDate,IFNULL(i.DueDate,''),i.TotalAmount,i.PaidAmount,i.Status,LEFT(IFNULL(i.Note,''),120),i.created_at,i.updated_at)
FROM Invoice i
JOIN StudentClass sc ON sc.ID=i.StudentClassID
JOIN Student s ON s.id=sc.StudentID
WHERE s.name='$SN' AND s.CampusID=$CAMPUS_ID
ORDER BY i.id;"

echo "--- InvoiceItem rows linked to this student's contracts ---"
"${M[@]}" -e "
SELECT CONCAT_WS('|',ii.id,ii.InvoiceID,ii.StudentClassID,ii.Amount,IFNULL(ii.PeriodStart,''),IFNULL(ii.PeriodEnd,''),LEFT(IFNULL(ii.Description,''),120),ii.created_at,ii.updated_at)
FROM InvoiceItem ii
JOIN StudentClass sc ON sc.ID=ii.StudentClassID
JOIN Student s ON s.id=sc.StudentID
WHERE s.name='$SN' AND s.CampusID=$CAMPUS_ID
ORDER BY ii.InvoiceID,ii.id;"

echo "--- Payment rows linked to this student's contract invoices ---"
"${M[@]}" -e "
SELECT CONCAT_WS('|',p.id,p.InvoiceID,p.Amount,IFNULL(p.PaidAt,''),IFNULL(p.Method,''),LEFT(IFNULL(p.Note,''),120),p.created_at)
FROM Payment p
JOIN Invoice i ON i.id=p.InvoiceID
JOIN StudentClass sc ON sc.ID=i.StudentClassID
JOIN Student s ON s.id=sc.StudentID
WHERE s.name='$SN' AND s.CampusID=$CAMPUS_ID
ORDER BY p.InvoiceID,p.id;"

echo "--- Session deduction ledger rows for target-date sessions ---"
"${M[@]}" -e "
SELECT CONCAT_WS('|',sdl.id,sdl.student_class_id,sdl.class_session_id,sdl.event_type,sdl.source,IFNULL(sdl.minutes,'null'),sdl.created_at)
FROM session_deduction_ledger sdl
WHERE sdl.class_session_id IN (
  SELECT cs.id FROM ClassSession cs JOIN StudentClass sc ON sc.ID=cs.StudentClassID JOIN Student s ON s.id=sc.StudentID
  WHERE s.name='$SN' AND s.CampusID=$CAMPUS_ID AND cs.SessionDate='$DATE'
)
ORDER BY sdl.id;"

echo "=== END ==="
