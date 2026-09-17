#!/usr/bin/env bash
# Staging smoke — health + branches + identity. Not production verification.
set -euo pipefail
source "$(cd "$(dirname "$0")" && pwd)/_common.sh"

bash "$(dirname "$0")/health.sh"

echo "=== staging runtime identity file ==="
bash "$(dirname "$0")/identity.sh"
python3 - <<PY
import json
from pathlib import Path
p=json.loads(Path("${IDENTITY_FILE}").read_text())
assert p.get("environment")=="staging", p
assert p.get("not_production") is True, p
assert len(p.get("backend_sha",""))==40, p
print("identity_ok", p["backend_sha"])
PY

echo "=== /api/v1/branches (staging synthetic data) ==="
# Unauthenticated behavior should be stable (401/403/200 empty) — record status.
CODE="$(curl -sS -o /tmp/staging-branches.json -w '%{http_code}' --max-time 15 "${STAGING_HTTP}/api/v1/branches" || true)"
echo "branches_http=${CODE}"
head -c 400 /tmp/staging-branches.json; echo

echo "=== pdo_mysql inside app ==="
compose exec -T app php -m | grep -i pdo_mysql
compose exec -T app php -r 'exit(extension_loaded("pdo_mysql")?0:1);'

echo "SMOKE_OK (STAGING_RUNTIME_VERIFIED candidate — not production verified)"
