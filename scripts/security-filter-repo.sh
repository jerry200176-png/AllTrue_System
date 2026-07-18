#!/usr/bin/env bash
# Purge secrets/PII from entire git history — REQUIRED before public repo (SECURITY.md §6).
#
# ⚠️ P0: Requires CEO-approved maintenance window. Temporarily relax branch protection,
# force-push ALL branches/tags, then restore protection. Never run casually on main.
#
# Usage (from a DISPOSABLE clone):
#   git clone --mirror git@github.com:OWNER/REPO.git /tmp/alltrue-mirror
#   cd /tmp/alltrue-mirror
#   bash /path/to/scripts/security-filter-repo.sh
#   git push --force --all
#   git push --force --tags
#
# Requires: git-filter-repo (pip install git-filter-repo)

set -euo pipefail

if ! command -v git-filter-repo >/dev/null 2>&1; then
  echo "Install: pip install git-filter-repo" >&2
  exit 1
fi

if [ ! -d .git ] && [ ! -f HEAD ]; then
  echo "Run from repo root or bare mirror." >&2
  exit 1
fi

REPLACE_FILE="$(mktemp)"
trap 'rm -f "$REPLACE_FILE"' EXIT
cat > "$REPLACE_FILE" <<'EOF'
regex:ghp_[A-Za-z0-9_]{20,}==>***REMOVED_GITHUB_PAT***
regex:gho_[A-Za-z0-9_]{20,}==>***REMOVED_GITHUB_OAUTH***
regex:github_pat_[A-Za-z0-9_]{20,}==>***REMOVED_GITHUB_PAT***
regex:[0-9]+:AA[A-Za-z0-9_-]{20,}==>***REMOVED_TELEGRAM_BOT_TOKEN***
EOF

echo "=== git-filter-repo: removing paths + redacting token patterns ==="

git filter-repo --force \
  --path .env.monitor --invert-paths \
  --path-glob '.cursor/projects/**' --invert-paths \
  --path-glob 'TelegramWebHook/**' --invert-paths \
  --path 'AllTrue (3).sql' --invert-paths \
  --path 'backups/alltrue_pre_perf_optimization_2026-04-16.sql' --invert-paths \
  --path 'backend/storage/backups/prd-e-20260418-232201.sql' --invert-paths \
  --path 'docs/AllTrue_backup.sql' --invert-paths \
  --path-glob 'docs/archive/control-plane-shadow-v1/config/platform/*.pem' --invert-paths \
  --path-glob 'TelegramWebHook/nppBackup/**' --invert-paths \
  --path-glob 'scripts/raspberry-pi/nppBackup/**' --invert-paths \
  --path 'backend/bootstrap/cache/config.php' --invert-paths \
  --replace-text "$REPLACE_FILE"

echo "=== Done. Run scripts/security-gitleaks-audit.sh then force-push in maintenance window. ==="
