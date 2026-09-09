#!/usr/bin/env bash
set -uo pipefail
ENV_FILE="${ENV_FILE:-/home/admin/backend/.env}"
DB_USER=$(grep '^DB_USERNAME=' "$ENV_FILE" | cut -d= -f2-)
DB_PASS=$(grep '^DB_PASSWORD=' "$ENV_FILE" | cut -d= -f2-)
DB_NAME=$(grep '^DB_DATABASE=' "$ENV_FILE" | cut -d= -f2-)
M=(mysql -h 127.0.0.1 -u "$DB_USER" -p"${DB_PASS}" "$DB_NAME" -N -B)
echo "=== effective verify $(date -Iseconds) ==="
"${M[@]}" -e "
SELECT CONCAT_WS('|',
  lr.id,
  lr.TeacherID, IFNULL(ltu.Name,'?'),
  sc.TeacherID, IFNULL(ctu.Name,'?'),
  CASE
    WHEN sub.teacher_id IS NOT NULL AND sub.teacher_id>0 THEN sub.teacher_id
    WHEN lr.TeacherID IS NOT NULL AND lr.TeacherID>0 THEN lr.TeacherID
    ELSE sc.TeacherID
  END,
  CASE
    WHEN sub.teacher_id IS NOT NULL AND sub.teacher_id>0 THEN IFNULL(su.Name,'?')
    WHEN lr.TeacherID IS NOT NULL AND lr.TeacherID>0 THEN IFNULL(ltu.Name,'?')
    ELSE IFNULL(ctu.Name,'?')
  END,
  CASE
    WHEN sub.teacher_id IS NOT NULL AND sub.teacher_id>0 THEN 'substitute'
    WHEN lr.TeacherID IS NOT NULL AND lr.TeacherID>0 THEN 'learning_record'
    ELSE 'student_class'
  END
)
FROM LearningRecord lr
JOIN StudentClass sc ON sc.ID=lr.StudentClassID
LEFT JOIN User ltu ON ltu.id=lr.TeacherID
LEFT JOIN User ctu ON ctu.id=sc.TeacherID
LEFT JOIN schedules sub ON sub.student_course_id=lr.StudentClassID
  AND sub.schedule_date=lr.SessionDate
  AND sub.status='scheduled'
  AND sub.original_schedule_id IS NOT NULL
  AND SUBSTRING(sub.start_time,1,5)=SUBSTRING(lr.StartTime,1,5)
LEFT JOIN User su ON su.id=sub.teacher_id
WHERE lr.id=18968;"
"${M[@]}" -e "SELECT COUNT(*) FROM schedules WHERE id IN (9889,9890);"
"${M[@]}" -e "SELECT CONCAT_WS('|',id,ResolvedAt,IFNULL(JSON_EXTRACT(Payload,'$.voided'),'null'),LEFT(Payload,300)) FROM Notifications WHERE id=17617;"
echo "=== END ==="
