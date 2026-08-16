#!/usr/bin/env bash
# Move 8/5 國文 ninth lesson onto unpaid renewal batches 3230/3231.
# First cancel the last future scheduled slot so capacity exists. Default dry-run.
set -euo pipefail

MODE="${MODE:-dry-run}"
STUDENT_KEY="${STUDENT_KEY:-}"
STUDENT_ID="${STUDENT_ID:-}"
STUDENT_NAME="${STUDENT_NAME:-}"
SOURCE_CLASS="${SOURCE_CLASS:-}"
TARGET_CLASS="${TARGET_CLASS:-}"
SESSION_ID="${SESSION_ID:-}"
CONFIRM="${CONFIRM:-}"
RUN_ID="${RUN_ID:-local}"
ACTOR="${ACTOR:-unknown}"
ENV_FILE="${ENV_FILE:-/home/admin/backend/.env}"
BACKEND_DIR="${BACKEND_DIR:-/home/admin/backend}"
APPLY_CONFIRM='APPROVE_MOVE_20260805_TO_3230_3231'

case "$STUDENT_KEY:$STUDENT_ID:$SOURCE_CLASS:$TARGET_CLASS:$SESSION_ID:$STUDENT_NAME" in
  zhang_zheng_ning:374:1681:3230:23157:張正甯) ;;
  zhang_zheng_le:373:1682:3231:27156:張正樂) ;;
  *)
    echo "SCOPE_MISMATCH: exact allowlist rejected"
    exit 1
    ;;
esac

case "$TARGET_CLASS" in
  2649|2606|1324)
    echo "SCOPE_MISMATCH: math batches forbidden"
    exit 1
    ;;
esac

if [ "$MODE" = apply ]; then
  [ "$CONFIRM" = "$APPLY_CONFIRM" ] || { echo "APPLY_BLOCKED: confirm string mismatch"; exit 1; }
elif [ "$MODE" != dry-run ]; then
  echo "MODE_BLOCKED: $MODE"
  exit 1
fi

DB_USER=$(grep '^DB_USERNAME=' "$ENV_FILE" | cut -d= -f2-)
DB_PASS=$(grep '^DB_PASSWORD=' "$ENV_FILE" | cut -d= -f2-)
DB_NAME=$(grep '^DB_DATABASE=' "$ENV_FILE" | cut -d= -f2-)
M=(mysql -h 127.0.0.1 -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -B)

echo "=== move 8/5 to chinese renewal mode=$MODE student=$STUDENT_KEY source=$SOURCE_CLASS target=$TARGET_CLASS session=$SESSION_ID run=$RUN_ID ==="

echo "--- source 8/5 session ---"
SRC=$("${M[@]}" -e "
SELECT CONCAT_WS('|',cs.id,cs.StudentClassID,sc.StudentID,s.name,IFNULL(sub.Subject_Name,'null'),
 DATE(cs.SessionDate),LEFT(cs.StartTime,5),LOWER(cs.Status),sc.Stop,IFNULL(sc.closed_reason,'null'),IFNULL(sc.Paid,'null'))
FROM ClassSession cs
JOIN StudentClass sc ON sc.ID=cs.StudentClassID
JOIN Student s ON s.id=sc.StudentID
LEFT JOIN Subject sub ON sub.id=sc.SubjectID
WHERE cs.id=${SESSION_ID};")
echo "$SRC"

echo "--- target batch ---"
TGT=$("${M[@]}" -e "
SELECT CONCAT_WS('|',sc.ID,sc.StudentID,IFNULL(sub.Subject_Name,'null'),sc.SessionCount,
 IFNULL(sc.UsedSessions,'null'),IFNULL(sc.RemainingSessions,'null'),IFNULL(sc.Paid,'null'),IFNULL(sc.Charge,'null'),sc.Stop,
 (SELECT COUNT(*) FROM ClassSession x WHERE x.StudentClassID=sc.ID AND LOWER(x.Status) IN ('scheduled','attended','completed','late')))
FROM StudentClass sc
LEFT JOIN Subject sub ON sub.id=sc.SubjectID
WHERE sc.ID=${TARGET_CLASS};")
echo "$TGT"

echo "--- last future scheduled slot on target ---"
SLOT=$("${M[@]}" -e "
SELECT CONCAT_WS('|',cs.id,DATE(cs.SessionDate),LEFT(cs.StartTime,5),LOWER(cs.Status),
 (SELECT COUNT(*) FROM StudentSingIn si WHERE si.ClassSessionID=cs.id AND si.VoidedAt IS NULL),
 (SELECT COUNT(*) FROM LearningRecord lr WHERE lr.ClassSessionID=cs.id))
FROM ClassSession cs
WHERE cs.StudentClassID=${TARGET_CLASS}
  AND LOWER(cs.Status)='scheduled'
  AND DATE(cs.SessionDate) >= '2026-08-19'
ORDER BY cs.SessionDate DESC, cs.id DESC
LIMIT 1;")
echo "${SLOT:-none}"

errors=()
[[ "$SRC" == "${SESSION_ID}|${SOURCE_CLASS}|${STUDENT_ID}|${STUDENT_NAME}|國文|2026-08-05|19:30|attended|"* ]] || errors+=("source_session")
[[ "$TGT" == "${TARGET_CLASS}|${STUDENT_ID}|國文|8|"*"|0|10800|0|"* ]] || errors+=("target_batch")
[ -n "$SLOT" ] || errors+=("no_future_slot")
slot_signin=$(echo "$SLOT" | cut -d'|' -f5)
slot_lr=$(echo "$SLOT" | cut -d'|' -f6)
[ "$slot_signin" = "0" ] || errors+=("slot_has_attendance")
[ "$slot_lr" = "0" ] || errors+=("slot_has_learning_record")

echo "PLAN cancel_last_future=$SLOT then transfer session=$SESSION_ID to target=$TARGET_CLASS paid=0"
if [ "${#errors[@]}" -gt 0 ]; then
  echo "PLAN_BLOCKED ${errors[*]}"
  echo "--- artisan preview (advisory) ---"
  cd "$BACKEND_DIR"
  php artisan repair:transfer-session-entitlement \
    "--source-class=$SOURCE_CLASS" "--target-class=$TARGET_CLASS" "--session-id=$SESSION_ID" || true
  exit 1
fi
echo "PLAN_OK"

if [ "$MODE" = dry-run ]; then
  echo "--- artisan preview before freeing a slot (expected blocked) ---"
  cd "$BACKEND_DIR"
  php artisan repair:transfer-session-entitlement \
    "--source-class=$SOURCE_CLASS" "--target-class=$TARGET_CLASS" "--session-id=$SESSION_ID" || true
  echo "DRY_RUN_ONLY: no production data changed."
  exit 0
fi

SLOT_ID=$(echo "$SLOT" | cut -d'|' -f1)
[[ "$SLOT_ID" =~ ^[0-9]+$ ]] || { echo "APPLY_BLOCKED: bad slot id"; exit 1; }

TS=$(date -u '+%Y%m%dT%H%M%SZ')
BACKUP_DIR=/home/admin/backups/emergency
mkdir -p "$BACKUP_DIR"
SNAPSHOT="$BACKUP_DIR/move_aug05_chinese_${STUDENT_KEY}_${RUN_ID}_${TS}.json"
PHP_B64="${PHP_B64:-}"
[ -n "$PHP_B64" ] || { echo "APPLY_BLOCKED: cancel PHP missing"; exit 1; }

cd "$BACKEND_DIR"
echo "$PHP_B64" | base64 -d > /tmp/ops-cancel-last-future-slot.php
SLOT_ID="$SLOT_ID" TARGET_CLASS="$TARGET_CLASS" php /tmp/ops-cancel-last-future-slot.php
rm -f /tmp/ops-cancel-last-future-slot.php

TRANSFER_OUT=$(ALLOW_PROD_REPAIR=1 php artisan repair:transfer-session-entitlement \
  "--source-class=$SOURCE_CLASS" "--target-class=$TARGET_CLASS" "--session-id=$SESSION_ID" \
  "--reference=P0-2026-08-05-chinese-renewal-$STUDENT_KEY-run-$RUN_ID" \
  "--reason=Move 2026-08-05 ninth 國文 lesson onto unpaid renewal batch" \
  "--actor=github-actions:$ACTOR:run-$RUN_ID" \
  --execute --force "--snapshot=$SNAPSHOT")
printf '%s\n' "$TRANSFER_OUT"
APPLIED_ID=$(printf '%s\n' "$TRANSFER_OUT" | sed -n 's/^TRANSFER_APPLIED id=//p' | tail -1)
[[ "$APPLIED_ID" =~ ^[0-9]+$ ]] || { echo "APPLY_FAILED: no transfer ID"; exit 1; }
php artisan repair:transfer-session-entitlement "--verify=$APPLIED_ID"

echo "--- post-check 8/5 owner ---"
OWNER=$("${M[@]}" -e "
SELECT CONCAT_WS('|',cs.id,cs.StudentClassID,DATE(cs.SessionDate),LOWER(cs.Status))
FROM ClassSession cs WHERE cs.id=${SESSION_ID};")
echo "$OWNER"
[[ "$OWNER" == "${SESSION_ID}|${TARGET_CLASS}|2026-08-05|attended" ]] || { echo "POSTCHECK_FAIL owner"; exit 1; }
echo "--- post-check target paid still 0 ---"
PAID_ROW=$("${M[@]}" -e "SELECT CONCAT_WS('|',ID,Paid,Charge,SessionCount,UsedSessions,RemainingSessions) FROM StudentClass WHERE ID=${TARGET_CLASS};")
echo "$PAID_ROW"
[[ "$PAID_ROW" == "${TARGET_CLASS}|0|10800|8|"* ]] || { echo "POSTCHECK_FAIL paid"; exit 1; }
echo "MOVE_OK transfer_id=$APPLIED_ID"
echo "=== END move 8/5 ==="
