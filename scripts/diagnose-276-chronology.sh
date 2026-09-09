#!/usr/bin/env bash
set -euo pipefail
ENV_FILE="${ENV_FILE:-/home/admin/backend/.env}"
DB_USER=$(grep '^DB_USERNAME=' "$ENV_FILE" | cut -d= -f2-)
DB_PASS=$(grep '^DB_PASSWORD=' "$ENV_FILE" | cut -d= -f2-)
DB_NAME=$(grep '^DB_DATABASE=' "$ENV_FILE" | cut -d= -f2-)
M=(mysql -h 127.0.0.1 -u "$DB_USER" -p"${DB_PASS}" "$DB_NAME" -N -B)
echo "=== #276 chronology probe generated=$(date -Iseconds) prod_head=$(git -C /home/admin/backend rev-parse HEAD) ==="

echo "--- StudentClass #2878 ---"
"${M[@]}" -e "SELECT CONCAT_WS('|',ID,StudentID,TeacherID,Stop,ScheduleMode,SessionCount,UsedSessions,RemainingSessions,IFNULL(created_at,''),IFNULL(updated_at,''),IFNULL(MDate,'')) FROM StudentClass WHERE ID=2878;"

echo "--- User names 53/289 ---"
"${M[@]}" -e "SELECT CONCAT_WS('|',id,Name,LoginName) FROM User WHERE id IN (53,289,263);"

echo "--- ClassSession #26509 ---"
"${M[@]}" -e "SELECT CONCAT_WS('|',id,StudentClassID,SessionDate,SUBSTRING(StartTime,1,5),SUBSTRING(EndTime,1,5),Status,IFNULL(created_at,''),IFNULL(updated_at,'')) FROM ClassSession WHERE id=26509;"

echo "--- schedules #9889 #9890 and neighbors for course 2878 on 2026-09-09 ---"
"${M[@]}" -e "SELECT CONCAT_WS('|',id,student_course_id,schedule_date,IFNULL(LEFT(start_time,5),''),IFNULL(LEFT(end_time,5),''),teacher_id,IFNULL(status,''),IFNULL(original_schedule_id,'null'),IFNULL(created_at,''),IFNULL(updated_at,'')) FROM schedules WHERE id IN (9889,9890) OR (student_course_id=2878 AND schedule_date='2026-09-09') ORDER BY id;"

echo "--- schedule columns present ---"
"${M[@]}" -e "SHOW COLUMNS FROM schedules;" | head -40

echo "--- LearningRecord #18968 ---"
"${M[@]}" -e "SELECT CONCAT_WS('|',id,ClassSessionID,StudentClassID,TeacherID,IFNULL(CreatedByUserID,'null'),Status,IFNULL(VoidedAt,''),IFNULL(created_at,''),IFNULL(updated_at,''),CHAR_LENGTH(IFNULL(Content,'')),IFNULL(LEFT(Content,80),'')) FROM LearningRecord WHERE id=18968;"

echo "--- StudentSingIn for session 26509 ---"
"${M[@]}" -e "SELECT CONCAT_WS('|',id,ClassSessionID,StudentClassID,TeacherID,IFNULL(RecordedByUserID,'null'),Status,IFNULL(SignInDT,''),IFNULL(VoidedAt,''),IFNULL(created_at,''),IFNULL(updated_at,'')) FROM StudentSingIn WHERE ClassSessionID=26509;"

echo "--- schedule_audit_logs for session 26509 ---"
"${M[@]}" -e "SELECT CONCAT_WS('|',id,session_id,action_type,LEFT(IFNULL(description,''),160),operator_id,IFNULL(u.Name,'?'),created_at) FROM schedule_audit_logs sal LEFT JOIN User u ON u.id=sal.operator_id WHERE session_id=26509 ORDER BY id;"

echo "--- schedule_change_log / related if exists for schedules 9889/9890 ---"
"${M[@]}" -e "SHOW TABLES LIKE 'schedule%';"
"${M[@]}" -e "SELECT CONCAT_WS('|',id,IFNULL(schedule_id,''),IFNULL(student_class_id,''),IFNULL(action,''),IFNULL(operator_id,''),IFNULL(created_at,''),LEFT(IFNULL(detail,''),200)) FROM schedule_change_log WHERE schedule_id IN (9889,9890) OR student_class_id=2878 ORDER BY id DESC LIMIT 40;" 2>/dev/null || echo "(no schedule_change_log or schema mismatch)"

echo "--- teacher change / substitute ops in schedule_audit for course around Sep 9 ---"
"${M[@]}" -e "SELECT CONCAT_WS('|',id,session_id,action_type,LEFT(IFNULL(description,''),200),operator_id,IFNULL(u.Name,'?'),created_at) FROM schedule_audit_logs sal LEFT JOIN User u ON u.id=sal.operator_id WHERE session_id=26509 OR description LIKE '%2878%' OR description LIKE '%9889%' OR description LIKE '%9890%' OR description LIKE '%黃喬%' OR description LIKE '%邱崴%' ORDER BY created_at DESC LIMIT 50;" 2>/dev/null || true

echo "--- SC teacher history if table exists ---"
"${M[@]}" -e "SHOW TABLES LIKE '%StudentClass%';"
"${M[@]}" -e "SHOW TABLES LIKE '%teacher%change%';"
"${M[@]}" -e "SHOW TABLES LIKE '%audit%';" | head -30

echo "=== END chronology ==="
