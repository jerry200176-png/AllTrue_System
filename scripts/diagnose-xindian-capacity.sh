#!/usr/bin/env bash
# Read-only production probe: Xindian (campus 9) demand/capacity aggregates.
# SELECT only. No PII (no student/teacher names). Safe to artifact.
set -euo pipefail

ENV_FILE="${ENV_FILE:-/home/admin/backend/.env}"
DB_USER=$(grep '^DB_USERNAME=' "$ENV_FILE" | cut -d= -f2-)
DB_PASS=$(grep '^DB_PASSWORD=' "$ENV_FILE" | cut -d= -f2-)
DB_NAME=$(grep '^DB_DATABASE=' "$ENV_FILE" | cut -d= -f2-)
M=(mysql -h 127.0.0.1 -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -B)

CAMPUS_ID=9
# Last 3 complete calendar months relative to probe day (Sep 2026 → Jun/Jul/Aug)
START_DATE='2026-06-01'
END_DATE='2026-08-31'
MONTHS="'2026-06','2026-07','2026-08'"

echo "=== xindian capacity probe generated=$(date -Iseconds) campus_id=${CAMPUS_ID} window=${START_DATE}..${END_DATE} read_only=1 ==="

echo "--- campus_identity ---"
"${M[@]}" -e "SELECT CONCAT_WS('|',id,IFNULL(name,''),IFNULL(code,''),IFNULL(Current,0),IFNULL(active,0)) FROM Campus WHERE id=${CAMPUS_ID};"

echo "--- schema_flags ---"
"${M[@]}" -e "
SELECT CONCAT_WS('|',
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Invoice' AND COLUMN_NAME='billing_period'),
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='teacher_subjects'),
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='UserCampus'),
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payroll_teacher_branch_rules'),
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schedules'),
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ClassSession' AND COLUMN_NAME='session_charge')
);"

echo "--- monthly_invoice_cash ---"
# Cash collected attributed to Xindian students. Prefer billing_period; else IssueDate YYYY-MM.
"${M[@]}" -e "
SELECT CONCAT_WS('|',
  COALESCE(NULLIF(i.billing_period,''), DATE_FORMAT(i.IssueDate,'%Y-%m')),
  COUNT(*),
  COALESCE(SUM(i.PaidAmount),0),
  COALESCE(SUM(i.TotalAmount),0),
  SUM(CASE WHEN LOWER(IFNULL(i.Status,'')) IN ('paid','partial','unpaid') THEN 1 ELSE 1 END)
)
FROM Invoice i
JOIN Student s ON s.id=i.StudentID
WHERE s.CampusID=${CAMPUS_ID}
  AND COALESCE(NULLIF(i.billing_period,''), DATE_FORMAT(i.IssueDate,'%Y-%m')) IN (${MONTHS})
GROUP BY COALESCE(NULLIF(i.billing_period,''), DATE_FORMAT(i.IssueDate,'%Y-%m'))
ORDER BY 1;"

echo "--- monthly_invoice_cash_by_status ---"
"${M[@]}" -e "
SELECT CONCAT_WS('|',
  COALESCE(NULLIF(i.billing_period,''), DATE_FORMAT(i.IssueDate,'%Y-%m')),
  LOWER(IFNULL(i.Status,'')),
  COUNT(*),
  COALESCE(SUM(i.PaidAmount),0)
)
FROM Invoice i
JOIN Student s ON s.id=i.StudentID
WHERE s.CampusID=${CAMPUS_ID}
  AND COALESCE(NULLIF(i.billing_period,''), DATE_FORMAT(i.IssueDate,'%Y-%m')) IN (${MONTHS})
GROUP BY 1,2
ORDER BY 1,2;"

echo "--- monthly_sessions_taught ---"
# Delivered-ish sessions at Xindian (student campus). Teacher from StudentClass.
"${M[@]}" -e "
SELECT CONCAT_WS('|',
  DATE_FORMAT(cs.SessionDate,'%Y-%m'),
  COUNT(*),
  COUNT(DISTINCT sc.TeacherID),
  COUNT(DISTINCT sc.StudentID),
  COALESCE(SUM(IFNULL(cs.session_charge,0)),0),
  COALESCE(SUM(TIMESTAMPDIFF(MINUTE, cs.StartTime, cs.EndTime)),0)
)
FROM ClassSession cs
JOIN StudentClass sc ON sc.ID=cs.StudentClassID
JOIN Student s ON s.id=sc.StudentID
WHERE s.CampusID=${CAMPUS_ID}
  AND cs.SessionDate BETWEEN '${START_DATE}' AND '${END_DATE}'
  AND LOWER(cs.Status) IN ('attended','completed','late','scheduled')
GROUP BY DATE_FORMAT(cs.SessionDate,'%Y-%m')
ORDER BY 1;"

echo "--- monthly_sessions_by_status ---"
"${M[@]}" -e "
SELECT CONCAT_WS('|',
  DATE_FORMAT(cs.SessionDate,'%Y-%m'),
  LOWER(cs.Status),
  COUNT(*)
)
FROM ClassSession cs
JOIN StudentClass sc ON sc.ID=cs.StudentClassID
JOIN Student s ON s.id=sc.StudentID
WHERE s.CampusID=${CAMPUS_ID}
  AND cs.SessionDate BETWEEN '${START_DATE}' AND '${END_DATE}'
GROUP BY 1,2
ORDER BY 1,3 DESC;"

echo "--- class_type_mix_sessions ---"
"${M[@]}" -e "
SELECT CONCAT_WS('|',
  DATE_FORMAT(cs.SessionDate,'%Y-%m'),
  COALESCE(NULLIF(sc.ClassType,''),'unknown'),
  COUNT(*),
  COUNT(DISTINCT CONCAT(cs.SessionDate,'|',LEFT(cs.StartTime,5),'|',IFNULL(sc.TeacherID,0)))
)
FROM ClassSession cs
JOIN StudentClass sc ON sc.ID=cs.StudentClassID
JOIN Student s ON s.id=sc.StudentID
WHERE s.CampusID=${CAMPUS_ID}
  AND cs.SessionDate BETWEEN '${START_DATE}' AND '${END_DATE}'
  AND LOWER(cs.Status) IN ('attended','completed','late','scheduled')
GROUP BY 1,2
ORDER BY 1,3 DESC;"

echo "--- concurrent_slot_headcount ---"
# Approx 1:N by counting distinct students sharing teacher+date+start at campus.
"${M[@]}" -e "
SELECT CONCAT_WS('|', bucket, COUNT(*), ROUND(AVG(headcount),2), MAX(headcount))
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
ORDER BY bucket;"

echo "--- peak_demand_weekday_hour ---"
"${M[@]}" -e "
SELECT CONCAT_WS('|',
  DAYOFWEEK(cs.SessionDate),
  HOUR(cs.StartTime),
  COUNT(*),
  COUNT(DISTINCT sc.TeacherID)
)
FROM ClassSession cs
JOIN StudentClass sc ON sc.ID=cs.StudentClassID
JOIN Student s ON s.id=sc.StudentID
WHERE s.CampusID=${CAMPUS_ID}
  AND cs.SessionDate BETWEEN '${START_DATE}' AND '${END_DATE}'
  AND LOWER(cs.Status) IN ('attended','completed','late','scheduled')
GROUP BY DAYOFWEEK(cs.SessionDate), HOUR(cs.StartTime)
ORDER BY COUNT(*) DESC
LIMIT 40;"

echo "--- peak_demand_subject_weekday_hour ---"
"${M[@]}" -e "
SELECT CONCAT_WS('|',
  COALESCE(sub.Subject_Name, CONCAT('id:',IFNULL(sc.SubjectID,'null'))),
  DAYOFWEEK(cs.SessionDate),
  HOUR(cs.StartTime),
  COUNT(*),
  COUNT(DISTINCT sc.TeacherID)
)
FROM ClassSession cs
JOIN StudentClass sc ON sc.ID=cs.StudentClassID
JOIN Student s ON s.id=sc.StudentID
LEFT JOIN Subject sub ON sub.id=sc.SubjectID
WHERE s.CampusID=${CAMPUS_ID}
  AND cs.SessionDate BETWEEN '${START_DATE}' AND '${END_DATE}'
  AND LOWER(cs.Status) IN ('attended','completed','late','scheduled')
GROUP BY 1,2,3
ORDER BY COUNT(*) DESC
LIMIT 60;"

echo "--- teachers_active_at_xindian ---"
"${M[@]}" -e "
SELECT CONCAT_WS('|',
  sc.TeacherID,
  COUNT(*),
  COUNT(DISTINCT DATE(cs.SessionDate)),
  COUNT(DISTINCT COALESCE(sub.Subject_Name, CONCAT('id:',IFNULL(sc.SubjectID,'null')))),
  COALESCE(SUM(TIMESTAMPDIFF(MINUTE, cs.StartTime, cs.EndTime)),0),
  MIN(LEFT(cs.StartTime,5)),
  MAX(LEFT(cs.EndTime,5))
)
FROM ClassSession cs
JOIN StudentClass sc ON sc.ID=cs.StudentClassID
JOIN Student s ON s.id=sc.StudentID
LEFT JOIN Subject sub ON sub.id=sc.SubjectID
WHERE s.CampusID=${CAMPUS_ID}
  AND cs.SessionDate BETWEEN '${START_DATE}' AND '${END_DATE}'
  AND LOWER(cs.Status) IN ('attended','completed','late','scheduled')
  AND sc.TeacherID IS NOT NULL AND sc.TeacherID>0
GROUP BY sc.TeacherID
ORDER BY COUNT(*) DESC;"

echo "--- teacher_home_campus_and_cross ---"
# Home campus from UserCampus (if present) else Teacher.CampusID legacy; cross load elsewhere.
"${M[@]}" -e "
SELECT CONCAT_WS('|',
  t.TeacherID,
  IFNULL(home.home_campus,'none'),
  t.xindian_sessions,
  IFNULL(x.other_campus_sessions,0),
  IFNULL(x.other_campus_count,0)
)
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
ORDER BY t.xindian_sessions DESC;"

echo "--- same_day_cross_campus_events ---"
# Days a Xindian-active teacher also taught elsewhere (travel-buffer signal).
"${M[@]}" -e "
SELECT CONCAT_WS('|', COUNT(*), COUNT(DISTINCT TeacherID), COUNT(DISTINCT d))
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
);"

echo "--- teacher_subjects_for_xindian_teachers ---"
"${M[@]}" -e "
SELECT CONCAT_WS('|',
  COALESCE(sub.Subject_Name, CONCAT('id:',ts.subject_id)),
  COUNT(DISTINCT ts.teacher_id)
)
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
GROUP BY 1
ORDER BY COUNT(DISTINCT ts.teacher_id) DESC
LIMIT 40;"

echo "--- subjects_taught_without_teacher_subjects_row ---"
"${M[@]}" -e "
SELECT CONCAT_WS('|',
  COALESCE(sub.Subject_Name, CONCAT('id:',sc.SubjectID)),
  COUNT(DISTINCT sc.TeacherID),
  COUNT(*)
)
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
GROUP BY 1
ORDER BY COUNT(*) DESC
LIMIT 30;"

echo "--- usercampus_teachers_with_xindian ---"
"${M[@]}" -e "
SELECT CONCAT_WS('|', COUNT(DISTINCT UserID),
  SUM(CASE WHEN CampusID=${CAMPUS_ID} THEN 1 ELSE 0 END))
FROM UserCampus;"

echo "--- usercampus_xindian_teacher_ids_count ---"
"${M[@]}" -e "
SELECT COUNT(DISTINCT UserID) FROM UserCampus WHERE CampusID=${CAMPUS_ID};"

echo "--- payroll_rules_xindian ---"
"${M[@]}" -e "
SELECT CONCAT_WS('|', COUNT(*), COUNT(DISTINCT teacher_user_id))
FROM payroll_teacher_branch_rules
WHERE branch_id=${CAMPUS_ID};"

echo "--- active_contracts_stock ---"
"${M[@]}" -e "
SELECT CONCAT_WS('|',
  COUNT(*),
  COALESCE(SUM(IFNULL(sc.Charge,0)),0),
  COALESCE(SUM(IFNULL(sc.Pay,0)),0),
  COALESCE(SUM(IFNULL(sc.RemainingSessions,0)),0),
  COALESCE(SUM(IFNULL(sc.SessionCount,0)),0),
  COALESCE(AVG(NULLIF(sc.Rate,0)),0)
)
FROM StudentClass sc
JOIN Student s ON s.id=sc.StudentID
WHERE s.CampusID=${CAMPUS_ID} AND sc.Stop=0;"

echo "--- revenue_per_delivered_session_proxy ---"
# Avg Charge/SessionCount on courses that had sessions in window (stock rate proxy).
"${M[@]}" -e "
SELECT CONCAT_WS('|',
  COUNT(DISTINCT sc.ID),
  ROUND(AVG(CASE WHEN IFNULL(sc.SessionCount,0)>0 THEN sc.Charge/sc.SessionCount END),0),
  ROUND(AVG(NULLIF(sc.Rate,0)),0),
  ROUND(AVG(IFNULL(cs.session_charge,0)),0),
  SUM(CASE WHEN IFNULL(cs.session_charge,0)>0 THEN 1 ELSE 0 END)
)
FROM ClassSession cs
JOIN StudentClass sc ON sc.ID=cs.StudentClassID
JOIN Student s ON s.id=sc.StudentID
WHERE s.CampusID=${CAMPUS_ID}
  AND cs.SessionDate BETWEEN '${START_DATE}' AND '${END_DATE}'
  AND LOWER(cs.Status) IN ('attended','completed','late');"

echo "--- fixed_schedules_branch_dow_hour ---"
"${M[@]}" -e "
SELECT CONCAT_WS('|',
  day_of_week,
  HOUR(STR_TO_DATE(start_time,'%H:%i')),
  COUNT(*),
  COUNT(DISTINCT teacher_id)
)
FROM schedules
WHERE branch_id=${CAMPUS_ID}
  AND LOWER(IFNULL(status,''))='scheduled'
GROUP BY 1,2
ORDER BY COUNT(*) DESC
LIMIT 40;"

echo "--- data_gaps_declared ---"
echo "no_teacher_preferred_availability_table|1"
echo "no_travel_buffer_config_field|1"
echo "availability_api_returns_busy_only|1"
echo "=== end probe ==="
