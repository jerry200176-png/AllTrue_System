#!/usr/bin/env bash
# #4022 gitleaks config self-test (runs in secret-scan.yml before the PR scan).
#  1. Rule-count guard: the effective rule set (pinned defaults + custom [[rules]])
#     must be non-empty; a config that silently replaces the defaults fails here.
#  2. Canary: synthetic secrets (GitHub PAT, AWS key, triage token) must be caught.
#  3. Triage rule shape, and the narrow identifier allowlist.
# Usage: <gitleaks-binary> [config]. Every value is synthetic and built at
# runtime, so no secret-shaped string is committed; output is redacted.
set -euo pipefail
BIN="${1:?gitleaks binary}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
CONFIG="${2:-$ROOT/.gitleaks.toml}"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT

rule_count() { # <config> -> number of effective rules
  mkdir -p "$TMP/empty"
  local defaults custom
  # 8.18.x logs one "adding <id> to base config" trace line per default rule it extends with.
  defaults=$("$BIN" detect --no-git --source "$TMP/empty" --config "$1" --no-banner --log-level trace --exit-code 0 2>&1 \
    | grep -c 'to base config' || true)
  custom=$(grep -c '^\[\[rules\]\]' "$1" || true)
  echo $((defaults + custom))
}
scan() { # <name> <content> [config] -> sorted rule ids found
  mkdir -p "$TMP/$1"; printf '%s\n' "$2" > "$TMP/$1/fixture.txt"
  "$BIN" detect --no-git --source "$TMP/$1" --config "${3:-$CONFIG}" --no-banner --redact --exit-code 0 \
    --report-format json --report-path "$TMP/$1.json" >/dev/null 2>&1
  python3 -c 'import json,sys; print(" ".join(sorted({f["RuleID"] for f in json.load(open(sys.argv[1])) or []})))' "$TMP/$1.json"
}
check() { [ "$2" = "$3" ] || { echo "FAIL $1: expected '$3', got '$2'"; exit 1; }; echo "ok $1"; }

secret="$(printf 'Ab3%.0s' $(seq 13))x"
pre="alltrue_""triage_"
canary="ghp = \"ghp_${secret:0:36}\"
aws = \"AKI""AQ2W3E4R5T6Y7U8I9\"
triage = \"12|${pre}${secret}\""

# Guard self-check: a config without [extend] has zero rules and catches nothing.
grep -v -e '^\[extend\]' -e '^useDefault' "$CONFIG" | python3 -c '
import re,sys; print(re.sub(r"\[\[rules\]\].*?(?=\n\[)", "", sys.stdin.read(), flags=re.S))' > "$TMP/zero.toml"
check guard-detects-zero-rules "$(rule_count "$TMP/zero.toml")" 0
check zero-rule-config-misses-canary "$(scan zero "$canary" "$TMP/zero.toml")" ""

n=$(rule_count "$CONFIG")
[ "$n" -gt 0 ] || { echo "FAIL rule-count: effective gitleaks rule count is 0 (config replaces the defaults?)"; exit 1; }
echo "ok rule-count ($n effective rules)"
check canary "$(scan canary "$canary")" "alltrue-triage-token aws-access-token github-pat"

check issued "$(scan issued "token = \"12|${pre}${secret}\"")" alltrue-triage-token
check bare-secret "$(scan bare "export T=${pre}${secret}")" alltrue-triage-token
check prefix-only "$(scan prefix "'secret_prefix' => '${pre}', forged 1|${pre}forged")" ""
# Known identifier false positives stay quiet; a random value under the same key still trips.
check fp-identifiers "$(scan fp "'key' => 'syn.eng.phonics.short-vowels.v1', key: 'weekly_16_segments', 'idempotency_key' => 'promo-exclude-1'
const clientEnabled = perfFlags.ADMISSIONS_FUNNEL_V1;")" ""
rnd="$(head -c 400 /dev/urandom | tr -dc 'A-Za-z0-9' | head -c 32)"
check generic-still-on "$(scan generic "'key' => '${rnd}'")" generic-api-key
