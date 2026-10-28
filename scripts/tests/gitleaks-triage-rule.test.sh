#!/usr/bin/env bash
# #4022 gitleaks config self-test (runs in secret-scan.yml before the PR scan).
#  1. Defaults are ON: a config without [extend] replaces them and the scan silently covers nothing,
#     so a synthetic GitHub PAT and AWS key must still be caught.
#  2. The triage-token rule: an issued token and a bare secret trip it; the bare prefix and a forged short value do not.
#  3. The narrow identifier allowlist stays quiet, while a random value under the same "key" word still trips.
# Usage: <gitleaks-binary> [config]. Every value is synthetic and built at runtime, so no
# secret-shaped string is committed; output is redacted.
set -euo pipefail
BIN="${1:?gitleaks binary}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
CONFIG="${2:-$ROOT/.gitleaks.toml}"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT

scan() { # <name> <content> -> sorted rule ids found
  mkdir -p "$TMP/$1"; printf '%s\n' "$2" > "$TMP/$1/fixture.txt"
  "$BIN" detect --no-git --source "$TMP/$1" --config "$CONFIG" --no-banner --redact --exit-code 0 \
    --report-format json --report-path "$TMP/$1.json" >/dev/null 2>&1
  python3 -c 'import json,sys; print(" ".join(sorted({f["RuleID"] for f in json.load(open(sys.argv[1])) or []})))' "$TMP/$1.json"
}
check() { [ "$2" = "$3" ] || { echo "FAIL $1: expected '$3', got '$2'"; exit 1; }; echo "ok $1"; }

secret="$(printf 'Ab3%.0s' $(seq 13))x"
pre="alltrue_""triage_"
check defaults-on "$(scan canary "ghp = \"ghp_${secret:0:36}\"
aws = \"AKI""AQ2W3E4R5T6Y7U8I9\"")" "aws-access-token github-pat"
check issued-token "$(scan issued "token = \"12|${pre}${secret}\"")" alltrue-triage-token
check bare-secret "$(scan bare "export T=${pre}${secret}")" alltrue-triage-token
check prefix-only "$(scan prefix "'secret_prefix' => '${pre}', forged 1|${pre}forged")" ""
check identifiers "$(scan ids "'key' => 'syn.eng.phonics.short-vowels.v1', key: 'weekly_16_segments', 'key' => 'perfFlags.ADMISSIONS_FUNNEL_V1'")" ""
check random-key-still-trips "$(scan random "config_api_key = \"kJ8dL2mN9qRxT5vWzY3aB7cE1fG4hI6p\"")" generic-api-key
