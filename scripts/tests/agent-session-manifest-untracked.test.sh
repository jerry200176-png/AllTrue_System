#!/usr/bin/env bash
# Fixture test: the local agent-start session manifest is not product content.
# Builds throwaway repos from SRC_ROOT's checker/.gitignore/.gitattributes and
# checks scripts/check-agent-provenance.sh keeps its claim semantics while a
# normal `git add -A` no longer commits .agent-session/manifest.json.
# SRC_ROOT defaults to this checkout; point it at an extracted older tree to
# get a baseline. Never touches real task worktrees.
set -uo pipefail

SRC_ROOT="${SRC_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
TASK_ROOT="/home/jerry/workspace/tasks/alltrue"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1
export GIT_AUTHOR_NAME=fixture GIT_AUTHOR_EMAIL=fixture@example.invalid
export GIT_COMMITTER_NAME=fixture GIT_COMMITTER_EMAIL=fixture@example.invalid
unset GITHUB_HEAD_REF GITHUB_BASE_REF GITHUB_BASE_SHA PR_BASE_SHA

pass=0; failed=0
result() { # name expected(pass|fail|yes|no) actual
  if [ "$2" = "$3" ]; then pass=$((pass + 1)); echo "ok   $1 ($3)";
  else failed=$((failed + 1)); echo "FAIL $1 expected=$2 actual=$3"; fi
}

manifest() { # path task branch base [prod_mutation] [preflight]
  mkdir -p "$(dirname "$1")"
  cat >"$1" <<JSON
{
  "schema_version": "1.0",
  "session_id": "fixture-$2",
  "project": "alltrue",
  "task_id": "$2",
  "repo_remote": "https://github.com/jerry200176-png/AllTrue_System.git",
  "base_sha": "$4",
  "branch": "$3",
  "worktree_path": "${TASK_ROOT}/$2",
  "started_at": "2026-09-27T00:00:00Z",
  "production_mutation": ${5:-false},
  "preflight_result": "${6:-pass}",
  "provenance_type": "agent-session"
}
JSON
}

# main: checker, ignore/attribute rules, human-authored.json; plus an inherited
# manifest from an older task when the rules under test still track it.
MAIN="$TMP/main"
git -c init.defaultBranch=main init -q "$MAIN"
mkdir -p "$MAIN/scripts" "$MAIN/.agent-session"
cp "$SRC_ROOT/scripts/check-agent-provenance.sh" "$MAIN/scripts/"
cp "$SRC_ROOT/.gitignore" "$SRC_ROOT/.gitattributes" "$MAIN/"
cp "$SRC_ROOT/.agent-session/human-authored.json" "$MAIN/.agent-session/"
echo product >"$MAIN/product.txt"
TRACKED=yes
git -C "$MAIN" check-ignore -q .agent-session/manifest.json && TRACKED=no
git -C "$MAIN" add -A
git -C "$MAIN" commit -qm base
if [ "$TRACKED" = yes ]; then
  manifest "$MAIN/.agent-session/manifest.json" OLDTASK chore/task-OLDTASK "$(git -C "$MAIN" rev-parse HEAD)"
  git -C "$MAIN" add -A && git -C "$MAIN" commit -qm "old task leftover"
fi
BASE="$(git -C "$MAIN" rev-parse HEAD)"
echo "SRC_ROOT=$SRC_ROOT manifest_tracked_on_main=$TRACKED base=$BASE"

# task <name> -> clone on chore/task-<name>, like agent-start: write local manifest.
task() {
  local dir="$TMP/$1"
  git clone -q "$MAIN" "$dir"
  git -C "$dir" switch -qc "chore/task-$1"
  manifest "$dir/.agent-session/manifest.json" "$1" "chore/task-$1" "$BASE"
  echo "$dir"
}
check() { # dir [base] -> pass|fail
  (cd "$1" && PR_BASE_SHA="${2:-$BASE}" bash scripts/check-agent-provenance.sh >"$1.log" 2>&1) && echo pass || echo fail
}
commit_all() { git -C "$1" add -A && git -C "$1" commit -qm "$2"; }
staged_manifest() {
  git -C "$1" diff --name-only "$BASE"...HEAD -- .agent-session/manifest.json | grep -q . && echo yes || echo no
}

# 1. Normal task change: base readable, no extra claim required.
d=$(task NOCLAIM); echo change >>"$d/product.txt"; commit_all "$d" change
result "1 normal PR passes without extra claim" pass "$(check "$d")"

# 2. Explicit invalid claims still fail (force-add where the file is ignored).
d=$(task BADTASK); manifest "$d/.agent-session/manifest.json" OTHER chore/task-OTHER "$BASE"
git -C "$d" add -f .agent-session/manifest.json; commit_all "$d" claim
result "2a claim with foreign task/branch fails" fail "$(check "$d")"
d=$(task BADBASE); manifest "$d/.agent-session/manifest.json" BADBASE chore/task-BADBASE 0123456789abcdef0123456789abcdef01234567
git -C "$d" add -f .agent-session/manifest.json; commit_all "$d" claim
result "2b claim with non-ancestor base_sha fails" fail "$(check "$d")"

# 3. production_mutation=true / preflight not passed are rejected.
d=$(task PRODMUT); manifest "$d/.agent-session/manifest.json" PRODMUT chore/task-PRODMUT "$BASE" true
git -C "$d" add -f .agent-session/manifest.json; commit_all "$d" claim
result "3a production_mutation=true fails" fail "$(check "$d")"
d=$(task NOPREFL); manifest "$d/.agent-session/manifest.json" NOPREFL chore/task-NOPREFL "$BASE" false fail
git -C "$d" add -f .agent-session/manifest.json; commit_all "$d" claim
result "3b preflight_result!=pass fails" fail "$(check "$d")"

# 4. An inherited/stale manifest is not this PR's evidence.
d="$TMP/INHERIT"; git clone -q "$MAIN" "$d"; git -C "$d" switch -qc chore/task-INHERIT
[ -f "$d/.agent-session/manifest.json" ] || manifest "$d/.agent-session/manifest.json" OLDTASK chore/task-OLDTASK "$BASE"
echo change >>"$d/product.txt"; commit_all "$d" change
r=$(check "$d"); grep -q "no session claim" "$d.log" || r="$r-validated"
result "4 inherited manifest not treated as claim" pass "$r"

# 5. Local session stays readable; normal git add leaves it out of the commit.
d=$(task LOCAL); echo change >>"$d/product.txt"; commit_all "$d" change
python3 -c 'import json,sys; assert json.load(open(sys.argv[1]))["session_id"]=="fixture-LOCAL"' \
  "$d/.agent-session/manifest.json" && readable=yes || readable=no
result "5a local manifest readable after commit" yes "$readable"
result "5b git add -A committed manifest" no "$(staged_manifest "$d")"

# 6. Two independent tasks land in sequence without a manifest conflict.
a=$(task TASKA); echo a >"$a/a.txt"; commit_all "$a" a
b=$(task TASKB); echo b >"$b/b.txt"; commit_all "$b" b
git -C "$MAIN" pull -q --no-rebase --no-edit "$a" chore/task-TASKA >/dev/null 2>&1
git -C "$b" pull -q --no-rebase --no-edit "$MAIN" main >/dev/null 2>&1 && clean=yes || clean=no
git -C "$b" merge --abort >/dev/null 2>&1
result "6 second task merges main cleanly" yes "$clean"

# 7. Existing side branches keep their behavior.
d=$(task HUMAN); git -C "$d" rm -q --cached --ignore-unmatch .agent-session/manifest.json
rm -f "$d/.agent-session/manifest.json"
python3 - "$d/.agent-session/human-authored.json" <<'PY'
import json,sys; p=sys.argv[1]; m=json.load(open(p)); m["declared_at"]="2026-09-27T00:00:00Z"; json.dump(m,open(p,"w"),indent=2)
PY
commit_all "$d" human
result "7a valid human-authored claim passes" pass "$(check "$d")"
d=$(task HUMANBAD); git -C "$d" rm -q --cached --ignore-unmatch .agent-session/manifest.json
rm -f "$d/.agent-session/manifest.json"
python3 - "$d/.agent-session/human-authored.json" <<'PY'
import json,sys; p=sys.argv[1]; m=json.load(open(p)); m["note"]="api_key=fixture"; json.dump(m,open(p,"w"),indent=2)
PY
commit_all "$d" human
result "7b human-authored claim with secret-like text fails" fail "$(check "$d")"
d=$(task UNREAD); echo change >>"$d/product.txt"; commit_all "$d" change
result "7c base unreadable, own local manifest valid" pass "$(check "$d" origin/no-such-base)"
# CI-style fresh checkout (no git-ignored local file), base unreadable: the
# required-file fallback must still validate human-authored.json, not skip it.
fresh() { git clone -q -b "chore/task-$2" "$TMP/$2" "$TMP/$1"; echo "$TMP/$1"; }
d=$(fresh CIFRESH INHERIT)
r=$(check "$d" origin/no-such-base)
[ -f "$d/.agent-session/manifest.json" ] || { grep -q "human-authored-ok" "$d.log" || r="$r-unvalidated"; }
result "7d base unreadable, no agent file: human-authored.json validated" pass "$r"
d=$(task HUMANSECRET); rm -f "$d/.agent-session/manifest.json"
git -C "$d" rm -q --cached --ignore-unmatch .agent-session/manifest.json
python3 - "$d/.agent-session/human-authored.json" <<'PY2'
import json,sys; p=sys.argv[1]; m=json.load(open(p)); m["note"]="api_key=fixture"; json.dump(m,open(p,"w"),indent=2)
PY2
commit_all "$d" human
d=$(fresh CIFRESHBAD HUMANSECRET)
result "7e base unreadable, no agent file, invalid human-authored.json fails" fail "$(check "$d" origin/no-such-base)"

echo "passed=$pass failed=$failed"
[ "$failed" -eq 0 ]
