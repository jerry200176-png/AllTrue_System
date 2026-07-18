#!/usr/bin/env bash
# Compare AllTrue Constitution Version vs sunrise OVERLAY pin.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CONST="${CONSTITUTION_FILE:-$ROOT/docs/governance/COMPANY_CONSTITUTION.md}"
OVERLAY="${OVERLAY_FILE:-}"

fail() { echo "overlay-pin: FAIL: $*" >&2; exit 1; }
ok() { echo "overlay-pin: OK: $*"; }

if [[ ! -f "$CONST" ]]; then
  fail "missing constitution $CONST"
fi

VER=$(grep -E '^\*\*Version:\*\*' "$CONST" | head -1 | sed -E 's/.*Version:\*\*[[:space:]]*//;s/[[:space:]]*$//')
[[ -n "$VER" ]] || fail "could not parse Constitution Version"

if [[ -z "$OVERLAY" ]]; then
  # Try sibling checkout, else fetch from GitHub raw
  if [[ -f /home/jerry/workspace/sunrise-cafe/docs/governance/OVERLAY.md ]]; then
    OVERLAY=/home/jerry/workspace/sunrise-cafe/docs/governance/OVERLAY.md
  else
    TMP=$(mktemp)
    if curl -fsSL "https://raw.githubusercontent.com/jerry200176-png/sunrise-cafe/main/docs/governance/OVERLAY.md" -o "$TMP"; then
      OVERLAY="$TMP"
    else
      fail "no OVERLAY_FILE and cannot fetch sunrise OVERLAY.md"
    fi
  fi
fi

[[ -f "$OVERLAY" ]] || fail "missing overlay $OVERLAY"
PIN=$(grep -Eo 'v?[0-9]+\.[0-9]+\.[0-9]+' "$OVERLAY" | head -1 | sed 's/^v//')
[[ -n "$PIN" ]] || fail "could not parse overlay pin version"

# Strip leading v from constitution if present
VER_NORM="${VER#v}"
PIN_NORM="${PIN#v}"

if [[ "$VER_NORM" != "$PIN_NORM" ]]; then
  # Breaking: major differs → fail; else fail for now (strict pin equality)
  fail "pin drift constitution=$VER_NORM overlay=$PIN_NORM"
fi

ok "constitution=$VER_NORM overlay=$PIN_NORM file=$OVERLAY"
exit 0
