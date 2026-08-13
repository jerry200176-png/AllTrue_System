#!/usr/bin/env bash
# Allowlisted 國文續購：8 堂、原金額、未入帳。Default is read-only.
# Apply calls production purchaseBatch; never records payment; never transfers 8/5.
set -euo pipefail

MODE="${MODE:-dry-run}"
STUDENT_KEY="${STUDENT_KEY:-}"
STUDENT_ID="${STUDENT_ID:-}"
STUDENT_NAME="${STUDENT_NAME:-}"
SOURCE_CLASS="${SOURCE_CLASS:-}"
SESSIONS="${SESSIONS:-8}"
START_DATE="${START_DATE:-2026-08-19}"
CONFIRM="${CONFIRM:-}"
RUN_ID="${RUN_ID:-local}"
ACTOR="${ACTOR:-unknown}"
ENV_FILE="${ENV_FILE:-/home/admin/backend/.env}"
BACKEND_DIR="${BACKEND_DIR:-/home/admin/backend}"
APPLY_CONFIRM='APPROVE_CHINESE_RENEWAL_UNPAID_8_SESSIONS_20260814'
PHP_B64="${PHP_B64:-}"

forbidden_source() {
  case "$1" in
    2649|2606|1324) return 0 ;;
    *) return 1 ;;
  esac
}

case "$STUDENT_KEY:$STUDENT_ID:$SOURCE_CLASS:$STUDENT_NAME" in
  zhang_zheng_ning:374:1681:張正甯) ;;
  zhang_zheng_le:373:1682:張正樂) ;;
  *)
    echo "SCOPE_MISMATCH: exact allowlist rejected ($STUDENT_KEY/$STUDENT_ID/$SOURCE_CLASS)"
    exit 1
    ;;
esac

if forbidden_source "$SOURCE_CLASS"; then
  echo "SCOPE_MISMATCH: math/payment batches are forbidden sources"
  exit 1
fi

if [ "$SESSIONS" != "8" ] || [ "$START_DATE" != "2026-08-19" ]; then
  echo "SCOPE_MISMATCH: only 8 sessions starting 2026-08-19 are allowed"
  exit 1
fi

if [ "$MODE" = apply ]; then
  if [ "$CONFIRM" != "$APPLY_CONFIRM" ]; then
    echo "APPLY_BLOCKED: confirm string mismatch"
    exit 1
  fi
elif [ "$MODE" != "dry-run" ]; then
  echo "MODE_BLOCKED: $MODE"
  exit 1
fi

DB_USER=$(grep '^DB_USERNAME=' "$ENV_FILE" | cut -d= -f2-)
DB_PASS=$(grep '^DB_PASSWORD=' "$ENV_FILE" | cut -d= -f2-)
DB_NAME=$(grep '^DB_DATABASE=' "$ENV_FILE" | cut -d= -f2-)
M=(mysql -h 127.0.0.1 -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -B)

echo "=== chinese renewal unpaid mode=$MODE student=$STUDENT_KEY source=$SOURCE_CLASS generated=$(date -Iseconds) run=$RUN_ID actor=$ACTOR ==="
echo "CONTRACT sessions=$SESSIONS start_date=$START_DATE paid=0 transfer=no"

echo "--- source batch ---"
SOURCE_ROW=$("${M[@]}" -e "
SELECT CONCAT_WS('|',
 sc.ID,sc.StudentID,s.name,s.CampusID,IFNULL(sc.SubjectID,'null'),IFNULL(sub.Subject_Name,'null'),
 sc.ScheduleMode,sc.SessionCount,IFNULL(sc.UsedSessions,'null'),IFNULL(sc.RemainingSessions,'null'),
 IFNULL(sc.Charge,'null'),IFNULL(sc.Rate,'null'),IFNULL(sc.rate_unit,'session'),
 IFNULL(sc.Paid,'null'),IFNULL(sc.Pay,'null'),sc.Stop,IFNULL(sc.PackageID,'null'))
FROM StudentClass sc
JOIN Student s ON s.id=sc.StudentID
LEFT JOIN Subject sub ON sub.id=sc.SubjectID
WHERE sc.ID=${SOURCE_CLASS};")
echo "$SOURCE_ROW"

IFS='|' read -r sc_id sc_student sc_name sc_campus sc_subject sc_subject_name sc_mode sc_count sc_used sc_remaining sc_charge sc_rate sc_rate_unit sc_paid sc_pay sc_stop sc_package <<<"$SOURCE_ROW"

errors=()
[ "$sc_id" = "$SOURCE_CLASS" ] || errors+=("source_id")
[ "$sc_student" = "$STUDENT_ID" ] || errors+=("student_id")
[ "$sc_name" = "$STUDENT_NAME" ] || errors+=("student_name")
[ "$sc_campus" = "16" ] || errors+=("campus")
[ "$sc_mode" = "count" ] || errors+=("schedule_mode")
[ "$sc_count" = "8" ] || errors+=("source_session_count")
[ "$sc_package" = "null" ] || errors+=("package_member")
[[ "$sc_subject_name" == *國文* ]] || errors+=("subject_not_chinese")
[[ "$sc_subject_name" == *數學* ]] && errors+=("subject_is_math")

computed_charge=$(awk -v rate="$sc_rate" -v sessions="$SESSIONS" 'BEGIN { printf "%.0f", rate * sessions }')
if [ "$sc_rate_unit" != "session" ] && [ "$sc_rate_unit" != "null" ] && [ -n "$sc_rate_unit" ]; then
  if [ "$sc_rate_unit" = "hour" ]; then
    errors+=("hour_rate_unit_requires_manual_review")
  fi
fi
if [ "$computed_charge" != "$sc_charge" ]; then
  errors+=("charge_would_change source=$sc_charge computed=$computed_charge")
fi

echo "--- existing same-subject count batches ---"
"${M[@]}" -e "
SELECT CONCAT_WS('|',sc.ID,sc.Stop,sc.SessionCount,IFNULL(sc.UsedSessions,'null'),
 IFNULL(sc.RemainingSessions,'null'),IFNULL(sc.Charge,'null'),IFNULL(sc.Paid,'null'),
 IFNULL(sc.StartDate,'null'),IFNULL(sub.Subject_Name,'null'))
FROM StudentClass sc
LEFT JOIN Subject sub ON sub.id=sc.SubjectID
WHERE sc.StudentID=${STUDENT_ID}
  AND sc.SubjectID=${sc_subject}
  AND sc.ScheduleMode='count'
ORDER BY sc.ID;"

echo "--- duplicate candidate for this contract ---"
DUP=$("${M[@]}" -e "
SELECT CONCAT_WS('|',sc.ID,IFNULL(sc.Paid,'null'),IFNULL(sc.Charge,'null'),sc.SessionCount,IFNULL(sc.StartDate,'null'))
FROM StudentClass sc
WHERE sc.StudentID=${STUDENT_ID}
  AND sc.SubjectID=${sc_subject}
  AND sc.ScheduleMode='count'
  AND sc.StartDate='${START_DATE}'
  AND sc.SessionCount=${SESSIONS}
  AND (sc.Stop IS NULL OR sc.Stop=0)
  AND sc.ID<>${SOURCE_CLASS}
ORDER BY sc.ID LIMIT 1;")
if [ -n "$DUP" ]; then
  echo "DUPLICATE_EXISTS $DUP"
else
  echo "DUPLICATE_EXISTS none"
fi

echo "--- 8/5 session still on source (must not move in this operation) ---"
"${M[@]}" -e "
SELECT CONCAT_WS('|',cs.id,cs.StudentClassID,DATE(cs.SessionDate),LEFT(cs.StartTime,5),LOWER(cs.Status))
FROM ClassSession cs
WHERE cs.StudentClassID=${SOURCE_CLASS}
  AND DATE(cs.SessionDate)='2026-08-05';"

echo "PLAN paid=0 pay=0 sessions=${SESSIONS} charge=${sc_charge} start=${START_DATE} transfer=no"
echo "TRANSFER_SLOT_WARNING: official purchase-batch will materialize 8 future sessions; moving 8/5 later needs one free slot."

if [ "${#errors[@]}" -gt 0 ]; then
  echo "PLAN_BLOCKED ${errors[*]}"
  exit 1
fi
echo "PLAN_OK"

if [ "$MODE" = "dry-run" ]; then
  echo "DRY_RUN_ONLY: no production data changed."
  exit 0
fi

if [ -n "$DUP" ]; then
  dup_paid=$(echo "$DUP" | cut -d'|' -f2)
  dup_charge=$(echo "$DUP" | cut -d'|' -f3)
  dup_count=$(echo "$DUP" | cut -d'|' -f4)
  if [ "$dup_paid" = "0" ] && [ "$dup_charge" = "$sc_charge" ] && [ "$dup_count" = "8" ]; then
    echo "IDEMPOTENT_SKIP already_unpaid_contract=$DUP"
    exit 0
  fi
  echo "APPLY_BLOCKED: duplicate contract is not the unpaid 8-session original-amount batch"
  exit 1
fi

TS=$(date -u '+%Y%m%dT%H%M%SZ')
BACKUP_DIR=/home/admin/backups/emergency
mkdir -p "$BACKUP_DIR"
SNAPSHOT="$BACKUP_DIR/chinese_renewal_unpaid_${STUDENT_KEY}_${RUN_ID}_${TS}.sql.gz"
{
  mysqldump -h 127.0.0.1 -u "$DB_USER" -p"$DB_PASS" --single-transaction --no-tablespaces \
    "$DB_NAME" StudentClass --where="ID=${SOURCE_CLASS}"
  mysqldump -h 127.0.0.1 -u "$DB_USER" -p"$DB_PASS" --single-transaction --no-tablespaces \
    "$DB_NAME" ClassSession --where="StudentClassID=${SOURCE_CLASS}"
} | gzip > "$SNAPSHOT"
echo "SNAPSHOT=$SNAPSHOT"

if [ -z "$PHP_B64" ]; then
  echo "APPLY_BLOCKED: PHP invoker missing"
  exit 1
fi
echo "$PHP_B64" | base64 -d > /tmp/ops-chinese-renewal-unpaid.php
chmod 600 /tmp/ops-chinese-renewal-unpaid.php
(
  cd "$BACKEND_DIR"
  SOURCE_CLASS="$SOURCE_CLASS" STUDENT_ID="$STUDENT_ID" STUDENT_NAME="$STUDENT_NAME" \
  SESSIONS="$SESSIONS" START_DATE="$START_DATE" EXPECTED_CHARGE="$sc_charge" \
  php /tmp/ops-chinese-renewal-unpaid.php
)
rm -f /tmp/ops-chinese-renewal-unpaid.php
echo "=== END chinese renewal unpaid ==="
