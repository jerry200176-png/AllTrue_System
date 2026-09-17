#!/usr/bin/env bash
# Staging rehearsal for merged #3015 / in-app #296 (school suggestions). Not production verified.
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=./_common.sh
source "${DIR}/_common.sh"

EVID="$(staging_evidence_dir)"
TS="$(date -u +%Y%m%dT%H%M%SZ)"
OUT="${EVID}/rehearse-inapp-296-${TS}.txt"
exec > >(tee -a "${OUT}") 2>&1

SHA="$(git -C "${REPO_ROOT}" rev-parse HEAD)"
echo "=== in-app #296 staging rehearsal @ ${SHA} ==="

bash "${DIR}/health.sh"

echo "=== schools API (auth expected without token) ==="
CODE="$(curl -sS -o /tmp/staging-schools.json -w '%{http_code}' --max-time 15 \
  "${STAGING_HTTP}/api/v1/schools?q=%E5%BB%BA%E4%B8%AD&limit=5" || true)"
echo "schools_http=${CODE}"
head -c 500 /tmp/staging-schools.json; echo

echo "=== frontend bundle probe (SchoolNameInput / typeahead) ==="
INDEX="$(curl -fsS "${STAGING_HTTP}/" | tr '"' '\n' | rg -o 'assets/[^ ]+\.js' | head -30 || true)"
echo "${INDEX}" | head -10
for chunk in $(echo "${INDEX}" | rg 'SchoolNameInput|StudentsList|index-' || true); do
  case "$chunk" in
    assets/*) url="${STAGING_HTTP}/${chunk}";;
    *) url="${STAGING_HTTP}/assets/${chunk}";;
  esac
  echo "fetch ${url}"
  curl -fsS "${url}" -o "/tmp/$(basename "$chunk")" 2>/dev/null || true
done
python3 <<'PY'
from pathlib import Path
needles = ['schools?q=', 'SchoolNameInput', '建議', 'allowCustom', 'canonical']
for p in Path('/tmp').glob('*.js'):
    t = p.read_text(errors='ignore')
    if not any(n in t for n in needles):
        continue
    print('===', p.name, '===')
    for n in needles:
        print(n, n in t)
PY

echo "=== manual checklist (Founder/staff via SSH tunnel) ==="
cat <<'CHK'
1. Open http://127.0.0.1:18080 (via SSH -L 18080:127.0.0.1:18080)
2. Students → school field typeahead returns curated suggestions
3. Selecting canonical option stores canonical value
4. Free-text fallback still allowed when no match
5. No production credentials in browser network tab
CHK

echo "REHEARSE_296_CANDIDATE log=${OUT}"
