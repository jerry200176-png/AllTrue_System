#!/usr/bin/env bash
# Read-only production probe: Xindian (campus 9) demand/capacity aggregates.
# SELECT only. No PII (no student/teacher names). TSV columns (tab-separated).
set -euo pipefail

ENV_FILE="${ENV_FILE:-/home/admin/backend/.env}"
DB_USER=$(grep '^DB_USERNAME=' "$ENV_FILE" | cut -d= -f2-)
DB_PASS=$(grep '^DB_PASSWORD=' "$ENV_FILE" | cut -d= -f2-)
DB_NAME=$(grep '^DB_DATABASE=' "$ENV_FILE" | cut -d= -f2-)
# -B batch, tab-separated; -N no headers
M=(mysql -h 127.0.0.1 -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -B)

CAMPUS_ID=9
START_DATE='2026-06-01'
END_DATE='2026-08-31'
MONTHS="'2026-06','2026-07','2026-08'"

echo "=== xindian capacity probe generated=$(date -Iseconds) campus_id=${CAMPUS_ID} window=${START_DATE}..${END_DATE} read_only=1 ==="

echo "--- campus_identity ---"
"${M[@]}" -e "SELECT id, IFNULL(name,''), IFNULL(code,''), IFNULL(Current,0), IFNULL(active,0) FROM Campus WHERE id=${CAMPUS_ID};"

echo "--- schema_flags ---"
"${M[@]}" -e "
SELECT
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Invoice' AND COLUMN_NAME='billing_period'),
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='teacher_subjects'),
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='UserCampus'),
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payroll_teacher_branch_rules'),
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schedules'),
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ClassSession' AND COLUMN_NAME='session_charge');
"

echo "--- monthly_invoice_cash ---"
"${M[@]}" -e "
SELECT
  COALESCE(NULLIF(i.billing_period,''), DATE_FORMAT(i.IssueDate,'%Y-%m')) AS ym,
  COUNT(*) AS invoice_count,
  COALESCE(SUM(i.PaidAmount),0) AS paid_sum,
  COALESCE(SUM(i.TotalAmount),0) AS total_sum
FROM Invoice i
JOIN Student s ON s.id=i.StudentID
WHERE s.CampusID=${CAMPUS_ID}
  AND COALESCE(NULLIF(i.billing_period,''), DATE_FORMAT(i.IssueDate,'%Y-%m')) IN (${MONTHS})
GROUP BY ym
ORDER BY ym;
"

echo "--- monthly_invoice_cash_by_status ---"
"${M[@]}" -e "
SELECT
  COALESCE(NULLIF(i.billing_period,''), DATE_FORMAT(i.IssueDate,'%Y-%m')) AS ym,
  LOWER(IFNULL(i.Status,'')) AS st,
  COUNT(*) AS cnt,
  COALESCE(SUM(i.PaidAmount),0) AS paid_sum
FROM Invoice i
JOIN Student s ON s.id=i.StudentID
WHERE s.CampusID=${CAMPUS_ID}
  AND COALESCE(NULLIF(i.billing_period,''), DATE_FORMAT(i.IssueDate,'%Y-%m')) IN (${MONTHS})
GROUP BY ym, st
ORDER BY ym, st;
"

echo "--- monthly_sessions_taught ---"
"${M[@]}" -e "
SELECT
  DATE_FORMAT(cs.SessionDate,'%Y-%m') AS ym,
  COUNT(*) AS sessions,
  COUNT(DISTINCT sc.TeacherID) AS teachers,
  COUNT(DISTINCT sc.StudentID) AS students,
  COALESCE(SUM(IFNULL(cs.session_charge,0)),0) AS charge_sum,
  COALESCE(SUM(TIMESTAMPDIFF(MINUTE, cs.StartTime, cs.EndTime)),0) AS minutes
FROM ClassSession cs
JOIN StudentClass sc ON sc.ID=cs.StudentClassID
JOIN Student s ON s.id=sc.StudentID
WHERE s.CampusID=${CAMPUS_ID}
  AND cs.SessionDate BETWEEN '${START_DATE}' AND '${END_DATE}'
  AND LOWER(cs.Status) IN ('attended','completed','late','scheduled')
GROUP BY ym
ORDER BY ym;
"

echo "--- monthly_sessions_attended_only ---"
"${M[@]}" -e "
SELECT
  DATE_FORMAT(cs.SessionDate,'%Y-%m') AS ym,
  COUNT(*) AS sessions,
  COUNT(DISTINCT sc.TeacherID) AS teachers,
  COALESCE(SUM(TIMESTAMPDIFF(MINUTE, cs.StartTime, cs.EndTime)),0) AS minutes
FROM ClassSession cs
JOIN StudentClass sc ON sc.ID=cs.StudentClassID
JOIN Student s ON s.id=sc.StudentID
WHERE s.CampusID=${CAMPUS_ID}
  AND cs.SessionDate BETWEEN '${START_DATE}' AND '${END_DATE}'
  AND LOWER(cs.Status) IN ('attended','completed','late')
GROUP BY ym
ORDER BY ym;
"

echo "--- monthly_sessions_by_status ---"
"${M[@]}" -e "
SELECT
  DATE_FORMAT(cs.SessionDate,'%Y-%m') AS ym,
  LOWER(cs.Status) AS st,
  COUNT(*) AS cnt
FROM ClassSession cs
JOIN StudentClass sc ON sc.ID=cs.StudentClassID
JOIN Student s ON s.id=sc.StudentID
WHERE s.CampusID=${CAMPUS_ID}
  AND cs.SessionDate BETWEEN '${START_DATE}' AND '${END_DATE}'
GROUP BY ym, st
ORDER BY ym, cnt DESC;
"

echo "--- class_type_mix_sessions ---"
"${M[@]}" -e "
SELECT
  DATE_FORMAT(cs.SessionDate,'%Y-%m') AS ym,
  COALESCE(NULLIF(sc.ClassType,''),'unknown') AS class_type,
  COUNT(*) AS sessions,
  COUNT(DISTINCT CONCAT(cs.SessionDate,'|',LEFT(cs.StartTime,5),'|',IFNULL(sc.TeacherID,0))) AS teacher_slots
FROM ClassSession cs
JOIN StudentClass sc ON sc.ID=cs.StudentClassID
JOIN Student s ON s.id=sc.StudentID
WHERE s.CampusID=${CAMPUS_ID}
  AND cs.SessionDate BETWEEN '${START_DATE}' AND '${END_DATE}'
  AND LOWER(cs.Status) IN ('attended','completed','late','scheduled')
GROUP BY ym, class_type
ORDER BY ym, sessions DESC;
"

echo "--- concurrent_slot_headcount ---"
"${M[@]}" -e "
SELECT bucket, COUNT(*) AS slots, ROUND(AVG(headcount),2) AS avg_head, MAX(headcount) AS max_head
FROM (
  SELECT
    CASE
      WHEN COUNT(DISTINCT sc.StudentID)=1 THEN '1v1'
      WHEN COUNT(DISTINCT sc.StudentID)=2 THEN '1v2'
      WHEN COUNT(DISTINCT sc.StudentID)>=3 THEN '1v3plus'
      ELSE 'other'
    END AS bucket,
    COUNT(DISTINCT sc.StudentID) AS headcount
  FROM ClassSession cs
  JOIN StudentClass sc ON sc.ID=cs.StudentClassID
  JOIN Student s ON s.id=sc.StudentID
  WHERE s.CampusID=${CAMPUS_ID}
    AND cs.SessionDate BETWEEN '${START_DATE}' AND '${END_DATE}'
    AND LOWER(cs.Status) IN ('attended','completed','late','scheduled')
    AND sc.TeacherID IS NOT NULL AND sc.TeacherID>0
  GROUP BY sc.TeacherID, cs.SessionDate, LEFT(cs.StartTime,5)
) t
GROUP BY bucket
ORDER BY bucket;
"

echo "--- peak_demand_weekday_hour ---"
"${M[@]}" -e "
SELECT
  DAYOFWEEK(cs.SessionDate) AS dow,
  HOUR(cs.StartTime) AS hr,
  COUNT(*) AS sessions,
  COUNT(DISTINCT sc.TeacherID) AS teachers
FROM ClassSession cs
JOIN StudentClass sc ON sc.ID=cs.StudentClassID
JOIN Student s ON s.id=sc.StudentID
WHERE s.CampusID=${CAMPUS_ID}
  AND cs.SessionDate BETWEEN '${START_DATE}' AND '${END_DATE}'
  AND LOWER(cs.Status) IN ('attended','completed','late','scheduled')
GROUP BY dow, hr
ORDER BY sessions DESC
LIMIT 40;
"

echo "--- peak_demand_subject_weekday_hour ---"
"${M[@]}" -e "
SELECT
  COALESCE(sub.Subject_Name, CONCAT('id:',IFNULL(sc.SubjectID,'null'))) AS subject_name,
  DAYOFWEEK(cs.SessionDate) AS dow,
  HOUR(cs.StartTime) AS hr,
  COUNT(*) AS sessions,
  COUNT(DISTINCT sc.TeacherID) AS teachers
FROM ClassSession cs
JOIN StudentClass sc ON sc.ID=cs.StudentClassID
JOIN Student s ON s.id=sc.StudentID
LEFT JOIN Subject sub ON sub.id=sc.SubjectID
WHERE s.CampusID=${CAMPUS_ID}
  AND cs.SessionDate BETWEEN '${START_DATE}' AND '${END_DATE}'
  AND LOWER(cs.Status) IN ('attended','completed','late','scheduled')
GROUP BY subject_name, dow, hr
ORDER BY sessions DESC
LIMIT 60;
"

echo "--- teachers_active_at_xindian ---"
"${M[@]}" -e "
SELECT
  sc.TeacherID AS teacher_id,
  COUNT(*) AS sessions,
  COUNT(DISTINCT DATE(cs.SessionDate)) AS days,
  COUNT(DISTINCT COALESCE(sub.Subject_Name, CONCAT('id:',IFNULL(sc.SubjectID,'null')))) AS subjects,
  COALESCE(SUM(TIMESTAMPDIFF(MINUTE, cs.StartTime, cs.EndTime)),0) AS minutes,
  MIN(LEFT(cs.StartTime,5)) AS earliest,
  MAX(LEFT(cs.EndTime,5)) AS latest
FROM ClassSession cs
JOIN StudentClass sc ON sc.ID=cs.StudentClassID
JOIN Student s ON s.id=sc.StudentID
LEFT JOIN Subject sub ON sub.id=sc.SubjectID
WHERE s.CampusID=${CAMPUS_ID}
  AND cs.SessionDate BETWEEN '${START_DATE}' AND '${END_DATE}'
  AND LOWER(cs.Status) IN ('attended','completed','late','scheduled')
  AND sc.TeacherID IS NOT NULL AND sc.TeacherID>0
GROUP BY teacher_id
ORDER BY sessions DESC;
"

echo "--- teacher_home_campus_and_cross ---"
"${M[@]}" -e "
SELECT
  t.TeacherID AS teacher_id,
  IFNULL(home.home_campus,'none') AS home_campus,
  t.xindian_sessions,
  IFNULL(x.other_campus_sessions,0) AS other_sessions,
  IFNULL(x.other_campus_count,0) AS other_campus_count
FROM (
  SELECT sc.TeacherID AS TeacherID, COUNT(*) AS xindian_sessions
  FROM ClassSession cs
  JOIN StudentClass sc ON sc.ID=cs.StudentClassID
  JOIN Student s ON s.id=sc.StudentID
  WHERE s.CampusID=${CAMPUS_ID}
    AND cs.SessionDate BETWEEN '${START_DATE}' AND '${END_DATE}'
    AND LOWER(cs.Status) IN ('attended','completed','late','scheduled')
    AND sc.TeacherID IS NOT NULL AND sc.TeacherID>0
  GROUP BY sc.TeacherID
) t
LEFT JOIN (
  SELECT uc.UserID AS uid, MIN(uc.CampusID) AS home_campus
  FROM UserCampus uc
  GROUP BY uc.UserID
) home ON home.uid=t.TeacherID
LEFT JOIN (
  SELECT sc.TeacherID AS tid,
    COUNT(*) AS other_campus_sessions,
    COUNT(DISTINCT s.CampusID) AS other_campus_count
  FROM ClassSession cs
  JOIN StudentClass sc ON sc.ID=cs.StudentClassID
  JOIN Student s ON s.id=sc.StudentID
  WHERE s.CampusID<>${CAMPUS_ID}
    AND cs.SessionDate BETWEEN '${START_DATE}' AND '${END_DATE}'
    AND LOWER(cs.Status) IN ('attended','completed','late','scheduled')
    AND sc.TeacherID IS NOT NULL AND sc.TeacherID>0
  GROUP BY sc.TeacherID
) x ON x.tid=t.TeacherID
ORDER BY t.xindian_sessions DESC;
"

echo "--- same_day_cross_campus_events ---"
"${M[@]}" -e "
SELECT COUNT(*) AS events, COUNT(DISTINCT TeacherID) AS teachers, COUNT(DISTINCT d) AS days
FROM (
  SELECT sc.TeacherID AS TeacherID, DATE(cs.SessionDate) AS d
  FROM ClassSession cs
  JOIN StudentClass sc ON sc.ID=cs.StudentClassID
  JOIN Student s ON s.id=sc.StudentID
  WHERE s.CampusID=${CAMPUS_ID}
    AND cs.SessionDate BETWEEN '${START_DATE}' AND '${END_DATE}'
    AND LOWER(cs.Status) IN ('attended','completed','late','scheduled')
    AND sc.TeacherID IS NOT NULL AND sc.TeacherID>0
  GROUP BY sc.TeacherID, DATE(cs.SessionDate)
) xin
WHERE EXISTS (
  SELECT 1
  FROM ClassSession cs2
  JOIN StudentClass sc2 ON sc2.ID=cs2.StudentClassID
  JOIN Student s2 ON s2.id=sc2.StudentID
  WHERE sc2.TeacherID=xin.TeacherID
    AND DATE(cs2.SessionDate)=xin.d
    AND s2.CampusID<>${CAMPUS_ID}
    AND LOWER(cs2.Status) IN ('attended','completed','late','scheduled')
);
"

echo "--- teacher_subjects_for_xindian_teachers ---"
"${M[@]}" -e "
SELECT
  COALESCE(sub.Subject_Name, CONCAT('id:',ts.subject_id)) AS subject_name,
  COUNT(DISTINCT ts.teacher_id) AS teachers
FROM teacher_subjects ts
JOIN (
  SELECT DISTINCT sc.TeacherID AS tid
  FROM ClassSession cs
  JOIN StudentClass sc ON sc.ID=cs.StudentClassID
  JOIN Student s ON s.id=sc.StudentID
  WHERE s.CampusID=${CAMPUS_ID}
    AND cs.SessionDate BETWEEN '${START_DATE}' AND '${END_DATE}'
    AND LOWER(cs.Status) IN ('attended','completed','late','scheduled')
    AND sc.TeacherID IS NOT NULL AND sc.TeacherID>0
) t ON t.tid=ts.teacher_id
LEFT JOIN Subject sub ON sub.id=ts.subject_id
GROUP BY subject_name
ORDER BY teachers DESC
LIMIT 40;
"

echo "--- subjects_taught_without_teacher_subjects_row ---"
"${M[@]}" -e "
SELECT
  COALESCE(sub.Subject_Name, CONCAT('id:',sc.SubjectID)) AS subject_name,
  COUNT(DISTINCT sc.TeacherID) AS teachers,
  COUNT(*) AS sessions
FROM ClassSession cs
JOIN StudentClass sc ON sc.ID=cs.StudentClassID
JOIN Student s ON s.id=sc.StudentID
LEFT JOIN Subject sub ON sub.id=sc.SubjectID
LEFT JOIN teacher_subjects ts ON ts.teacher_id=sc.TeacherID AND ts.subject_id=sc.SubjectID
WHERE s.CampusID=${CAMPUS_ID}
  AND cs.SessionDate BETWEEN '${START_DATE}' AND '${END_DATE}'
  AND LOWER(cs.Status) IN ('attended','completed','late','scheduled')
  AND sc.TeacherID IS NOT NULL AND sc.TeacherID>0
  AND ts.teacher_id IS NULL
GROUP BY subject_name
ORDER BY sessions DESC
LIMIT 30;
"

echo "--- usercampus_xindian_teacher_count ---"
"${M[@]}" -e "SELECT COUNT(DISTINCT UserID) FROM UserCampus WHERE CampusID=${CAMPUS_ID};"

echo "--- payroll_rules_xindian ---"
"${M[@]}" -e "
SELECT COUNT(*) AS rules, COUNT(DISTINCT teacher_user_id) AS teachers
FROM payroll_teacher_branch_rules
WHERE branch_id=${CAMPUS_ID};
"

echo "--- active_contracts_stock ---"
"${M[@]}" -e "
SELECT
  COUNT(*) AS courses,
  COALESCE(SUM(IFNULL(sc.Charge,0)),0) AS charge_sum,
  COALESCE(SUM(IFNULL(sc.Pay,0)),0) AS pay_sum,
  COALESCE(SUM(IFNULL(sc.RemainingSessions,0)),0) AS remaining_sessions,
  COALESCE(SUM(IFNULL(sc.SessionCount,0)),0) AS session_count_sum,
  COALESCE(AVG(NULLIF(sc.Rate,0)),0) AS avg_rate
FROM StudentClass sc
JOIN Student s ON s.id=sc.StudentID
WHERE s.CampusID=${CAMPUS_ID} AND sc.Stop=0;
"

echo "--- revenue_per_delivered_session_proxy ---"
"${M[@]}" -e "
SELECT
  COUNT(DISTINCT sc.ID) AS courses,
  ROUND(AVG(CASE WHEN IFNULL(sc.SessionCount,0)>0 THEN sc.Charge/sc.SessionCount END),0) AS avg_charge_per_session,
  ROUND(AVG(NULLIF(sc.Rate,0)),0) AS avg_rate,
  ROUND(AVG(IFNULL(cs.session_charge,0)),0) AS avg_session_charge,
  SUM(CASE WHEN IFNULL(cs.session_charge,0)>0 THEN 1 ELSE 0 END) AS sessions_with_charge
FROM ClassSession cs
JOIN StudentClass sc ON sc.ID=cs.StudentClassID
JOIN Student s ON s.id=sc.StudentID
WHERE s.CampusID=${CAMPUS_ID}
  AND cs.SessionDate BETWEEN '${START_DATE}' AND '${END_DATE}'
  AND LOWER(cs.Status) IN ('attended','completed','late');
"

echo "--- fixed_schedules_branch_dow_hour ---"
"${M[@]}" -e "
SELECT
  day_of_week,
  HOUR(STR_TO_DATE(start_time,'%H:%i')) AS hr,
  COUNT(*) AS slots,
  COUNT(DISTINCT teacher_id) AS teachers
FROM schedules
WHERE branch_id=${CAMPUS_ID}
  AND LOWER(IFNULL(status,''))='scheduled'
GROUP BY day_of_week, hr
ORDER BY slots DESC
LIMIT 40;
"

echo "--- transfer_pool_other_campus_subject_overlap ---"
# Teachers who taught elsewhere in window, share a subject taught at Xindian demand,
# but had ZERO Xindian sessions — candidates not yet used at Xindian (IDs only).
"${M[@]}" -e "
SELECT
  other.TeacherID AS teacher_id,
  other.other_sessions,
  other.home_like_campus,
  COUNT(DISTINCT demand.subject_id) AS overlapping_subjects
FROM (
  SELECT sc.TeacherID AS TeacherID,
         COUNT(*) AS other_sessions,
         MIN(s.CampusID) AS home_like_campus
  FROM ClassSession cs
  JOIN StudentClass sc ON sc.ID=cs.StudentClassID
  JOIN Student s ON s.id=sc.StudentID
  WHERE s.CampusID<>${CAMPUS_ID}
    AND cs.SessionDate BETWEEN '${START_DATE}' AND '${END_DATE}'
    AND LOWER(cs.Status) IN ('attended','completed','late','scheduled')
    AND sc.TeacherID IS NOT NULL AND sc.TeacherID>0
  GROUP BY sc.TeacherID
) other
JOIN (
  SELECT DISTINCT sc.TeacherID AS TeacherID, sc.SubjectID AS subject_id
  FROM ClassSession cs
  JOIN StudentClass sc ON sc.ID=cs.StudentClassID
  JOIN Student s ON s.id=sc.StudentID
  WHERE s.CampusID<>${CAMPUS_ID}
    AND cs.SessionDate BETWEEN '${START_DATE}' AND '${END_DATE}'
    AND LOWER(cs.Status) IN ('attended','completed','late','scheduled')
) other_subj ON other_subj.TeacherID=other.TeacherID
JOIN (
  SELECT DISTINCT sc.SubjectID AS subject_id
  FROM ClassSession cs
  JOIN StudentClass sc ON sc.ID=cs.StudentClassID
  JOIN Student s ON s.id=sc.StudentID
  WHERE s.CampusID=${CAMPUS_ID}
    AND cs.SessionDate BETWEEN '${START_DATE}' AND '${END_DATE}'
    AND LOWER(cs.Status) IN ('attended','completed','late','scheduled')
) demand ON demand.subject_id=other_subj.subject_id
WHERE other.TeacherID NOT IN (
  SELECT DISTINCT sc.TeacherID
  FROM ClassSession cs
  JOIN StudentClass sc ON sc.ID=cs.StudentClassID
  JOIN Student s ON s.id=sc.StudentID
  WHERE s.CampusID=${CAMPUS_ID}
    AND cs.SessionDate BETWEEN '${START_DATE}' AND '${END_DATE}'
    AND LOWER(cs.Status) IN ('attended','completed','late','scheduled')
    AND sc.TeacherID IS NOT NULL AND sc.TeacherID>0
)
GROUP BY other.TeacherID, other.other_sessions, other.home_like_campus
ORDER BY overlapping_subjects DESC, other.other_sessions DESC
LIMIT 80;
"

echo "--- data_gaps_declared ---"
echo "no_teacher_preferred_availability_table	1"
echo "no_travel_buffer_config_field	1"
echo "availability_api_returns_busy_only	1"
echo "=== end probe ==="
