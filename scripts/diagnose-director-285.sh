#!/usr/bin/env bash
# Read-only production diagnosis for one allowlisted test director account.
# Fail-closed: identity must match exactly before any association counts are printed.
set -euo pipefail

ENV_FILE="${ENV_FILE:-/home/admin/backend/.env}"
DB_USER=$(grep '^DB_USERNAME=' "$ENV_FILE" | cut -d= -f2-)
DB_PASS=$(grep '^DB_PASSWORD=' "$ENV_FILE" | cut -d= -f2-)
DB_NAME=$(grep '^DB_DATABASE=' "$ENV_FILE" | cut -d= -f2-)
M=(mysql -h 127.0.0.1 -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -B)

ALLOW_USER_ID=285
ALLOW_LOGIN='w3-director-test-20260813'
ALLOW_TYPE='D'
ALLOW_CAMPUS_ID=9

echo "=== director 285 diagnosis generated=$(date -Iseconds) ==="
echo "ALLOWLIST user_id=${ALLOW_USER_ID} login=${ALLOW_LOGIN} type=${ALLOW_TYPE} campus_id=${ALLOW_CAMPUS_ID}"

echo "--- exact identity ---"
IDENTITY=$("${M[@]}" -e "
SELECT CONCAT_WS('|',
  IFNULL(u.id,'missing'),
  IFNULL(u.LoginName,'missing'),
  IFNULL(u.type,'missing'),
  IFNULL(GROUP_CONCAT(uc.CampusID ORDER BY uc.CampusID SEPARATOR ','), 'none')
)
FROM User u
LEFT JOIN UserCampus uc ON uc.UserID=u.id
WHERE u.id=${ALLOW_USER_ID}
GROUP BY u.id,u.LoginName,u.type;")

if [ -z "$IDENTITY" ]; then
  echo "BLOCKED|user_missing|id=${ALLOW_USER_ID}"
  echo "=== END READ-ONLY DIAGNOSIS ==="
  exit 1
fi

echo "$IDENTITY"

IFS='|' read -r GOT_ID GOT_LOGIN GOT_TYPE GOT_CAMPUSES <<<"$IDENTITY"
if [ "$GOT_ID" != "$ALLOW_USER_ID" ] || [ "$GOT_LOGIN" != "$ALLOW_LOGIN" ] || [ "$GOT_TYPE" != "$ALLOW_TYPE" ] || [ "$GOT_CAMPUSES" != "$ALLOW_CAMPUS_ID" ]; then
  echo "BLOCKED|identity_mismatch|expected=${ALLOW_USER_ID}|${ALLOW_LOGIN}|${ALLOW_TYPE}|${ALLOW_CAMPUS_ID}|observed=${IDENTITY}"
  echo "=== END READ-ONLY DIAGNOSIS ==="
  exit 1
fi
echo "IDENTITY_OK"

has_col() {
  local table="$1"
  local col="$2"
  "${M[@]}" -e "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='${table}' AND column_name='${col}';"
}

count_col() {
  local label="$1"
  local table="$2"
  local col="$3"
  local present
  present=$(has_col "$table" "$col")
  if [ "$present" = "1" ]; then
    "${M[@]}" -e "SELECT CONCAT('${label}|',COUNT(*)) FROM \`${table}\` WHERE \`${col}\`=${ALLOW_USER_ID};"
  else
    echo "${label}|column_absent"
  fi
}

echo "--- association counts owned only by this user id ---"
count_col 'UserCampus' UserCampus UserID
count_col 'auth_tokens' auth_tokens user_id
count_col 'user_login_activities' user_login_activities user_id
count_col 'user_notification_preferences' user_notification_preferences user_id
count_col 'NotificationReads' NotificationReads UserID
count_col 'password_reset_requests' password_reset_requests user_id
count_col 'Teacher' Teacher id

echo "--- foreign keys the product delete path would null, not delete ---"
count_col 'LearningRecord.ApprovedBy' LearningRecord ApprovedBy
count_col 'LearningRecord.CreatedByUserID' LearningRecord CreatedByUserID
count_col 'User.PasswordSetByUserID' User PasswordSetByUserID

echo "--- unexpected product-data ownership (must review before delete) ---"
count_col 'Student.CreatedByUserID' Student CreatedByUserID
count_col 'StudentClass.CreatedByUserID' StudentClass CreatedByUserID
count_col 'Invoice.CreatedByUserID' Invoice CreatedByUserID
count_col 'Payment.CreatedByUserID' Payment CreatedByUserID
count_col 'ClassSession.CreatedByUserID' ClassSession CreatedByUserID

echo "DELETE_CANDIDATE user_id=${ALLOW_USER_ID} login=${ALLOW_LOGIN} campus_id=${ALLOW_CAMPUS_ID}"
echo "PRODUCT_PATH super_admin destroy /api/v1/directors/${ALLOW_USER_ID}"
echo "=== END READ-ONLY DIAGNOSIS ==="
