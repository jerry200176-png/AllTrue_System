#!/usr/bin/env bash
set -uo pipefail
ENV_FILE="${ENV_FILE:-/home/admin/backend/.env}"
DB_USER=$(grep '^DB_USERNAME=' "$ENV_FILE" | cut -d= -f2-)
DB_PASS=$(grep '^DB_PASSWORD=' "$ENV_FILE" | cut -d= -f2-)
DB_NAME=$(grep '^DB_DATABASE=' "$ENV_FILE" | cut -d= -f2-)
M=(mysql -h 127.0.0.1 -u "$DB_USER" -p"${DB_PASS}" "$DB_NAME" -N -B)
echo "=== #276 chronology probe generated=$(date -Iseconds) prod_head=$(git -C /home/admin/backend rev-parse HEAD) ==="

echo "--- StudentClass columns ---"
"${M[@]}" -e "SHOW COLUMNS FROM StudentClass;" | cut -f1 | tr '\n' ' '; echo

echo "--- StudentClass #2878 ---"
"${M[@]}" -e "SELECT * FROM StudentClass WHERE ID=2878\G" | head -80

echo "--- Users 53/289/263 ---"
"${M[@]}" -e "SELECT CONCAT_WS('|',id,Name,LoginName) FROM User WHERE id IN (53,289,263);"

echo "--- ClassSession #26509 ---"
"${M[@]}" -e "SELECT CONCAT_WS('|',id,StudentClassID,SessionDate,SUBSTRING(StartTime,1,5),SUBSTRING(EndTime,1,5),Status,IFNULL(created_at,''),IFNULL(updated_at,'')) FROM ClassSession WHERE id=26509;"

echo "--- schedules columns ---"
"${M[@]}" -e "SHOW COLUMNS FROM schedules;" | cut -f1 | tr '\n' ' '; echo

echo "--- schedules #9889 #9890 ---"
"${M[@]}" -e "SELECT * FROM schedules WHERE id IN (9889,9890)\G" | head -120

echo "--- LearningRecord #18968 ---"
"${M[@]}" -e "SELECT CONCAT_WS('|',id,ClassSessionID,StudentClassID,TeacherID,IFNULL(CreatedByUserID,'null'),Status,IFNULL(VoidedAt,''),IFNULL(created_at,''),IFNULL(updated_at,''),CHAR_LENGTH(IFNULL(Content,'')),IFNULL(LEFT(Content,100),'')) FROM LearningRecord WHERE id=18968;"

echo "--- StudentSingIn session 26509 ---"
"${M[@]}" -e "SELECT CONCAT_WS('|',id,ClassSessionID,StudentClassID,TeacherID,IFNULL(RecordedByUserID,'null'),Status,IFNULL(SignInDT,''),IFNULL(VoidedAt,''),IFNULL(created_at,''),IFNULL(updated_at,'')) FROM StudentSingIn WHERE ClassSessionID=26509;" 2>/dev/null || \
"${M[@]}" -e "SELECT CONCAT_WS('|',id,ClassSessionID,StudentClassID,TeacherID,IFNULL(RecordedByUserID,'null'),Status,IFNULL(SignInDT,''),IFNULL(VoidedAt,'')) FROM StudentSingIn WHERE ClassSessionID=26509;"

echo "--- schedule_audit_logs session 26509 ---"
"${M[@]}" -e "SELECT CONCAT_WS('|',id,session_id,action_type,LEFT(IFNULL(description,''),200),operator_id,IFNULL(u.Name,'?'),created_at) FROM schedule_audit_logs sal LEFT JOIN User u ON u.id=sal.operator_id WHERE session_id=26509 ORDER BY id;"

echo "--- schedule_change_log for 9889/9890/2878 ---"
"${M[@]}" -e "SHOW TABLES LIKE 'schedule_change_log';"
"${M[@]}" -e "SHOW COLUMNS FROM schedule_change_log;" 2>/dev/null | cut -f1 | tr '\n' ' '; echo
"${M[@]}" -e "SELECT * FROM schedule_change_log WHERE schedule_id IN (9889,9890) OR student_class_id=2878 ORDER BY id DESC LIMIT 30\G" 2>/dev/null | head -200 || echo "(no rows / schema)"

echo "--- notifications substitute for ClassSession 26509 ---"
"${M[@]}" -e "SELECT CONCAT_WS('|',id,Type,SourceType,SourceID,IFNULL(ResolvedAt,''),IFNULL(created_at,''),IFNULL(updated_at,''),LEFT(IFNULL(Payload,''),180)) FROM Notifications WHERE SourceType='ClassSession' AND SourceID='26509' ORDER BY id;" 2>/dev/null || \
"${M[@]}" -e "SELECT CONCAT_WS('|',id,Type,SourceType,SourceID,IFNULL(ResolvedAt,''),created_at) FROM notifications WHERE SourceType='ClassSession' AND SourceID='26509' ORDER BY id;" 2>/dev/null || echo "(no notif)"

echo "--- recent audits mentioning 代課/更換/邱崴/黃喬 around course ---"
"${M[@]}" -e "SELECT CONCAT_WS('|',id,session_id,action_type,LEFT(IFNULL(description,''),220),operator_id,IFNULL(u.Name,'?'),created_at) FROM schedule_audit_logs sal LEFT JOIN User u ON u.id=sal.operator_id WHERE created_at>='2026-08-01' AND (session_id=26509 OR description LIKE '%26509%' OR description LIKE '%2878%' OR description LIKE '%代課%' AND (description LIKE '%何昀佳%' OR description LIKE '%2878%')) ORDER BY created_at DESC LIMIT 40;" 2>/dev/null || true

echo "=== END chronology ==="

echo "--- notification 17617 full payload ---"
"${M[@]}" -e "SELECT id,Type,SourceType,SourceID,ResolvedAt,created_at,updated_at,Payload FROM Notifications WHERE id=17617\G"

echo "--- schedule_audit_logs for 26509 (qualified) ---"
"${M[@]}" -e "SELECT CONCAT_WS('|',sal.id,sal.session_id,sal.action_type,LEFT(IFNULL(sal.description,''),220),sal.operator_id,IFNULL(u.Name,'?'),sal.created_at) FROM schedule_audit_logs sal LEFT JOIN User u ON u.id=sal.operator_id WHERE sal.session_id=26509 ORDER BY sal.id;"

echo "--- learning_record_teacher_changes for 18968 ---"
"${M[@]}" -e "SHOW TABLES LIKE 'learning_record_teacher_changes';"
"${M[@]}" -e "SELECT * FROM learning_record_teacher_changes WHERE learning_record_id=18968 ORDER BY id\G" 2>/dev/null || echo none

echo "--- all schedules for course 2878 on 2026-09-09 ---"
"${M[@]}" -e "SELECT CONCAT_WS('|',id,teacher_id,status,IFNULL(original_schedule_id,'null'),created_at,updated_at) FROM schedules WHERE student_course_id=2878 AND schedule_date='2026-09-09' ORDER BY id;"

echo "--- LR 18968 current ---"
"${M[@]}" -e "SELECT CONCAT_WS('|',id,TeacherID,Status,created_at,updated_at,CHAR_LENGTH(IFNULL(Content,''))) FROM LearningRecord WHERE id=18968;"
