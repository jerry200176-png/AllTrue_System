#!/usr/bin/env bash
# #878: single trace view for "is this GitHub issue's fix actually live yet".
#
# #165/#166 taught us "merged" != "deployed" — a merged PR can sit un-deployed
# (CI queue, deployable-file filter, deploy.yml failure) while an in-app bug
# reply already implies it shipped. This answers one question precisely:
# for a given GitHub issue, find its merging PR, then check whether that
# commit is an ancestor of what production is *actually* running right now
# (per the public, unauthenticated /deployment.json — no SSH, no secrets).
#
# Usage: scripts/trace-fix-deploy-status.sh <github-issue-number>
set -euo pipefail

ISSUE="${1:?Usage: trace-fix-deploy-status.sh <github-issue-number>}"
REPO="jerry200176-png/AllTrue_System"
PROD_URL="https://daan.lifenet.com.tw"

echo "=== Issue #${ISSUE} ==="
gh issue view "$ISSUE" --repo "$REPO" --json title,state,closedAt \
  --jq '"title: " + .title, "state: " + .state, "closed_at: " + (.closedAt // "still open")'

echo
echo "=== Linked / referencing PRs ==="
PRS=$(gh pr list --repo "$REPO" --state all --search "$ISSUE in:body" --json number,title,state,mergedAt,mergeCommit \
  --jq '.[] | select(.title | test("#'"$ISSUE"'\\b") or true) | [.number, .state, (.mergedAt // "not merged"), (.mergeCommit.oid // "-")] | @tsv')

if [ -z "$PRS" ]; then
  echo "No PR found referencing #${ISSUE}. Check manually — this script only searches PR body/title text."
  exit 1
fi
echo "$PRS" | while IFS=$'\t' read -r num state merged_at commit; do
  echo "  PR #${num} [${state}] merged_at=${merged_at} merge_commit=${commit}"
done

MERGED_PR=$(echo "$PRS" | awk -F'\t' '$2 == "MERGED" {print $1; exit}')
if [ -z "$MERGED_PR" ]; then
  echo
  echo "❌ No merged PR found for #${ISSUE} yet — nothing to check against production."
  exit 1
fi

# Do NOT trust `gh pr view --json mergeCommit` as the landed commit: this repo
# has had history-rewrite incidents (see AI_REGRESSION_LESSONS.md incident A),
# and squash-merge can also leave that field pointing at a pre-squash commit
# that never actually lands on main. Look up what ACTUALLY landed instead, by
# PR number in the commit message — same thing `git log --grep` on main sees.
git fetch origin main --quiet 2>/dev/null || true
MERGE_COMMIT=$(git log origin/main --oneline --grep="(#${MERGED_PR})" --format='%H' | tail -1)
if [ -z "$MERGE_COMMIT" ]; then
  echo
  echo "❌ PR #${MERGED_PR} is reported MERGED by the GitHub API, but no commit"
  echo "   on origin/main references '(#${MERGED_PR})' in its message. Check manually —"
  echo "   this can mean the merge was later reverted/rewritten."
  exit 1
fi

echo
echo "=== Production deployment identity (public, unauthenticated) ==="
DEPLOYMENT=$(curl -sf "${PROD_URL}/deployment.json") || {
  echo "❌ Could not fetch ${PROD_URL}/deployment.json"
  exit 1
}
echo "$DEPLOYMENT" | python3 -m json.tool
PROD_SHA=$(echo "$DEPLOYMENT" | python3 -c "import json,sys; print(json.load(sys.stdin)['backend_sha'])")

echo
echo "=== Is the merge commit live in production? ==="
git fetch origin main --quiet 2>/dev/null || true
if git cat-file -e "${MERGE_COMMIT}^{commit}" 2>/dev/null && git cat-file -e "${PROD_SHA}^{commit}" 2>/dev/null; then
  if git merge-base --is-ancestor "$MERGE_COMMIT" "$PROD_SHA"; then
    echo "✅ DEPLOYED — ${MERGE_COMMIT:0:8} is an ancestor of production's live ${PROD_SHA:0:8}."
    echo "   Safe to mark this in-app bug 'resolved' (per CHAT_BUG_SYSTEM.md §3.6-3.7)."
  else
    echo "⚠️  NOT YET DEPLOYED — ${MERGE_COMMIT:0:8} merged to main, but production is still on ${PROD_SHA:0:8}."
    echo "   Do NOT mark this in-app bug 'resolved' yet — this is exactly the #165/#166 false-resolved trap."
  fi
else
  echo "⚠️  Could not resolve one of the commits locally (shallow clone or SHA not fetched)."
  echo "   Merge commit: ${MERGE_COMMIT}  Production SHA: ${PROD_SHA}"
  echo "   Run 'git fetch origin main' with full history and retry."
fi
