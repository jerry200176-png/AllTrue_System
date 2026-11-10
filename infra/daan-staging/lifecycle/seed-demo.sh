#!/usr/bin/env bash
# Seed synthetic demo data. Usage: seed-demo.sh [--reset]   (--reset = migrate:fresh first; used nightly)
# Passwords are generated on first run into the git-ignored .env (DEMO_PW_*); usernames/IDs go to
# ~/alltrue-stage/DEMO_LOGINS.txt (no secrets). Never production data.
set -euo pipefail
source "$(cd "$(dirname "$0")" && pwd)/_common.sh"
require_env_file
NOTES="${STAGING_DEMO_NOTES:-${HOME}/alltrue-stage/DEMO_LOGINS.txt}"
for k in DEMO_PW_DIRECTOR DEMO_PW_TEACHER; do
  grep -q "^${k}=" "${ENV_FILE}" || echo "${k}=$(openssl rand -hex 8)" >>"${ENV_FILE}"
done
if [[ "${1:-}" == "--reset" ]]; then
  [[ "$(sed -n 's/^APP_ENV=//p' "${ENV_FILE}" | tail -1)" == "staging" ]] || { echo "refusing reset: APP_ENV != staging" >&2; exit 2; }
  compose_exec_noninteractive app php artisan migrate:fresh --force
fi
# shellcheck disable=SC1090
set -a; source <(grep '^DEMO_PW_' "${ENV_FILE}"); set +a
RESULT="$(compose exec -T -e DEMO_PW_DIRECTOR -e DEMO_PW_TEACHER app php < "${STAGE_ROOT}/seed-demo.php")"
echo "${RESULT}" | cut -c1-600
{ echo "# seeded $(date -u +%FT%TZ). Passwords: DEMO_PW_DIRECTOR / DEMO_PW_TEACHER in infra/daan-staging/.env on this host."
  echo "# 主任 (2 campuses): demo-director   老師: demo-teacher   家長: StudentID + Phone (no password)"
  python3 -c 'import json,sys; [print("#  ", s["id"], s["name"], s["phone"]) for s in json.loads(sys.argv[1])["students"]]' "${RESULT}"; } >"${NOTES}"
echo "SEED_OK"
