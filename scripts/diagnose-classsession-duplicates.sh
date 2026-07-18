#!/usr/bin/env bash
# Read-only Pi diagnostic: cross-SC scheduled overlaps + Stop=1 orphans.
# Usage (on Pi): DATE=2026-07-18 CAMPUS_ID=9 TEACHER_NAME=黃芝琳 \
#   STUDENT_NAMES='王品方,陳品承' bash scripts/diagnose-classsession-duplicates.sh
set -euo pipefail
DATE="${DATE:-$(date +%Y-%m-%d)}"
CAMPUS_ID="${CAMPUS_ID:-9}"
TEACHER_NAME="${TEACHER_NAME:-黃芝琳}"
STUDENT_NAMES="${STUDENT_NAMES:-王品方,陳品承}"
ENV_FILE="${ENV_FILE:-/home/admin/backend/.env}"
DB_PASS=$(grep '^DB_PASSWORD=' "$ENV_FILE" | cut -d= -f2-)
DB_NAME=$(grep '^DB_DATABASE=' "$ENV_FILE" | cut -d= -f2-)
MYSQL=(mysql -h 127.0.0.1 -u admin -p"${DB_PASS}" "$DB_NAME" -N -B)

IFS=',' read -r -a NAMES <<< "$STUDENT_NAMES"
IN_LIST=""
for n in "${NAMES[@]}"; do
  n=$(echo "$n" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
  [ -z "$n" ] && continue
  esc=$(printf "%s" "$n" | sed "s/'/\\\\'/g")
  IN_LIST="${IN_LIST:+$IN_LIST,}'$esc'"
done

echo "=== ClassSession duplicate diagnose DATE=$DATE CAMPUS=$CAMPUS_ID ==="
"${MYSQL[@]}" -e "SELECT id,Name FROM User WHERE Name LIKE CONCAT('%','${TEACHER_NAME}','%') LIMIT 5;"
"${MYSQL[@]}" -e "SELECT id,name,CampusID FROM Student WHERE name IN ($IN_LIST) AND CampusID=${CAMPUS_ID};"
echo "--- case sessions ±7d ---"
"${MYSQL[@]}" -e "
SELECT CONCAT_WS('|', s.name, cs.id, cs.StudentClassID, sc.Stop, sc.SessionCount,
  cs.SessionDate, SUBSTRING(cs.StartTime,1,5), cs.Status, LEFT(IFNULL(cs.Note,''),60))
FROM ClassSession cs
JOIN StudentClass sc ON sc.ID=cs.StudentClassID
JOIN Student s ON s.id=sc.StudentID
WHERE s.name IN ($IN_LIST) AND s.CampusID=${CAMPUS_ID}
  AND cs.SessionDate BETWEEN DATE_SUB('${DATE}', INTERVAL 7 DAY) AND DATE_ADD('${DATE}', INTERVAL 7 DAY)
  AND LOWER(cs.Status)<>'cancelled'
ORDER BY s.name, cs.SessionDate, cs.StartTime, cs.StudentClassID;"
echo "--- campus scheduled cross-SC ---"
"${MYSQL[@]}" -e "
SELECT CONCAT_WS('|', st.name, cs.SessionDate, SUBSTRING(cs.StartTime,1,5),
  GROUP_CONCAT(DISTINCT cs.StudentClassID), GROUP_CONCAT(cs.id), GROUP_CONCAT(sc.Stop))
FROM ClassSession cs
JOIN StudentClass sc ON sc.ID=cs.StudentClassID
JOIN Student st ON st.id=sc.StudentID
WHERE st.CampusID=${CAMPUS_ID} AND cs.SessionDate>='${DATE}' AND LOWER(cs.Status)='scheduled'
GROUP BY st.id, st.name, cs.SessionDate, SUBSTRING(cs.StartTime,1,5)
HAVING COUNT(DISTINCT cs.StudentClassID)>1 LIMIT 100;"
echo "--- orphan Stop=1 scheduled count ---"
"${MYSQL[@]}" -e "
SELECT COUNT(*) FROM ClassSession cs
JOIN StudentClass sc ON sc.ID=cs.StudentClassID
WHERE sc.Stop=1 AND LOWER(cs.Status)='scheduled' AND cs.SessionDate>='${DATE}';"
cd /home/admin/backend
php artisan classsession:audit-duplicates --branch_id="${CAMPUS_ID}" 2>&1 | tail -40 || true
php artisan fix:orphan-scheduled-sessions --dry-run 2>&1 | tail -40 || true
echo "=== END ==="
