#!/usr/bin/env bash
# #4022 fixture: the repo .gitleaks.toml keeps the default rules and flags an
# issued triage token, but not the bare prefix. Usage: <gitleaks-binary>.
# Tokens are built at runtime so no token-shaped string is committed.
set -euo pipefail
BIN="${1:?gitleaks binary}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
scan() { # <name> <content> -> prints rule ids found
  mkdir -p "$TMP/$1"; cp "$ROOT/.gitleaks.toml" "$TMP/$1/"; printf '%s\n' "$2" > "$TMP/$1/fixture.txt"
  "$BIN" detect --no-git --source "$TMP/$1" --no-banner --redact --exit-code 0 \
    --report-format json --report-path "$TMP/$1.json" >/dev/null 2>&1
  python3 -c 'import json,sys; print(" ".join(sorted({f["RuleID"] for f in json.load(open(sys.argv[1])) or []})))' "$TMP/$1.json"
}
secret="$(printf 'Ab3%.0s' $(seq 13))x"
pre="alltrue_""triage_"
check() { [ "$2" = "$3" ] || { echo "FAIL $1: expected '$3', got '$2'"; exit 1; }; echo "ok $1"; }
check issued "$(scan issued "token = \"12|${pre}${secret}\"")" alltrue-triage-token
check bare-secret "$(scan bare "export T=${pre}${secret}")" alltrue-triage-token
check prefix-only "$(scan prefix "'secret_prefix' => '${pre}', forged 1|${pre}forged")" ""
check defaults-on "$(scan pat "ghp = \"ghp_${secret:0:36}\"")" github-pat
