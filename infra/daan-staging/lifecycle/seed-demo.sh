#!/usr/bin/env bash
# Seed synthetic demo accounts. Passwords are generated on first run and kept ONLY in
# ~/alltrue-stage/DEMO_LOGINS.txt (0600, host-side). Never from production data.
set -euo pipefail
source "$(cd "$(dirname "$0")" && pwd)/_common.sh"
require_env_file
NOTES="${STAGING_DEMO_NOTES:-${HOME}/alltrue-stage/DEMO_LOGINS.txt}"
if [[ ! -f "${NOTES}" ]]; then
  umask 077
  printf 'DEMO_PW_DIRECTOR=%s\nDEMO_PW_TEACHER=%s\n' "$(openssl rand -hex 8)" "$(openssl rand -hex 8)" >"${NOTES}"
fi
# shellcheck disable=SC1090
set -a; source <(grep '^DEMO_PW_' "${NOTES}"); set +a
RESULT="$(compose exec -T -e DEMO_PW_DIRECTOR -e DEMO_PW_TEACHER app php < "${STAGE_ROOT}/seed-demo.php")"
echo "${RESULT}"
# Append (replace) the non-secret ID section for the parent login.
sed -i '/^# --- seeded/,$d' "${NOTES}"
{ echo "# --- seeded $(date -u +%FT%TZ): director/teacher login = LoginName demo-director / demo-teacher + password above"
  echo "# parent login = StudentID + Phone (no password):"
  python3 -c 'import json,sys; [print("#  ", s["id"], s["name"], s["phone"]) for s in json.loads(sys.argv[1])["students"]]' "${RESULT}"; } >>"${NOTES}"
echo "SEED_OK (logins noted in ${NOTES})"
