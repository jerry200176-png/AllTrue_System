#!/usr/bin/env bash
# --prune is opt-in: no flag => no `sync`; --prune => `sync --wing <docs> --apply <this repo root>`;
# --prune --dry-run => preview only. Uses a mock mempalace; nothing touches a real palace.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
mkdir -p "$T/palace" "$T/tr"
cat >"$T/mock" <<'M'
#!/usr/bin/env bash
echo "$*" >>"$MOCK_LOG"
exit 0
M
chmod +x "$T/mock"

run() { # args...
  : >"$T/log"
  HOME="$T" MEMPALACE="$T/mock" MOCK_LOG="$T/log" MEMPALACE_PALACE_DIR="$T/palace" \
    MEMPALACE_RUN_ROOT="$T/runs" TRANSCRIPT_DIR="$T/tr" \
    bash "$ROOT/scripts/mempalace-ingest.sh" --no-lock "$@" >"$T/out" 2>&1 || { cat "$T/out"; return 1; }
}

fail() { echo "FAIL: $*"; cat "$T/log"; exit 1; }

run
! grep -q '^sync' "$T/log" || fail "sync ran without --prune"

run --prune
grep -q "^sync --wing alltrue-docs --apply $ROOT\$" "$T/log" || fail "expected apply sync on this repo root"

run --prune --dry-run
grep -q "^sync --wing alltrue-docs --dry-run $ROOT\$" "$T/log" || fail "dry-run must not apply"
! grep -q -- '--apply' "$T/log" || fail "dry-run applied"
# --help must be output-only (no command substitution of the usage text)
run --help
! grep -q . "$T/log" || fail "--help executed mempalace"
echo "ok"
