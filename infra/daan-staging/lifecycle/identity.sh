#!/usr/bin/env bash
# Write staging runtime identity (never call this production verified).
set -euo pipefail
source "$(cd "$(dirname "$0")" && pwd)/_common.sh"

mkdir -p "${STAGE_ROOT}/runtime"
SHA="$(git -C "${REPO_ROOT}" rev-parse HEAD)"
NOW="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
python3 - <<PY
import json
from pathlib import Path
payload = {
  "schema": 1,
  "environment": "staging",
  "project": "alltrue-stage",
  "backend_sha": "${SHA}",
  "frontend_sha": "${SHA}",
  "deployed_at": "${NOW}",
  "source": "infra/daan-staging",
  "http_bind": "127.0.0.1:18080",
  "not_production": True,
}
Path("${IDENTITY_FILE}").write_text(json.dumps(payload, indent=2) + "\n")
print(json.dumps(payload, indent=2))
PY

echo "IDENTITY_OK sha=${SHA} file=${IDENTITY_FILE}"
