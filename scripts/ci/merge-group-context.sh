#!/usr/bin/env bash
# GitHub merge queue (merge_group) helper. Required checks must report under merge_group with the same
# job names, but the event has no pull_request payload and github.sha is the queue's merged result.
#   --parse <head_ref>  print the PR number from refs/heads/gh-readonly-queue/main/pr-<N>-<40hex>
#   (no args)           needs MG_HEAD_REF, MG_BASE_SHA, GH_TOKEN, GITHUB_REPOSITORY, GITHUB_ENV (after checkout,
#                       fetch-depth 0): exports MG_PR_NUMBER / MG_BRANCH / MG_PR_TITLE and points origin/main at the
#                       queue base so existing `origin/main...HEAD` diffs cover only this PR.
set -euo pipefail

parse() {
  [[ "$1" =~ ^(refs/heads/)?gh-readonly-queue/.+/pr-([0-9]+)-[0-9a-f]{40}$ ]] || return 1
  printf '%s\n' "${BASH_REMATCH[2]}"
}

if [ "${1:-}" = "--parse" ]; then parse "${2:-}"; exit; fi

pr="$(parse "$MG_HEAD_REF")" || { echo "::error::cannot derive PR number from merge_group head_ref '$MG_HEAD_REF'"; exit 1; }
mapfile -t meta < <(gh api "repos/${GITHUB_REPOSITORY}/pulls/${pr}" --jq '.head.ref, (.title | gsub("[\r\n]"; " "))')
git cat-file -e "${MG_BASE_SHA}^{commit}" || { echo "::error::merge_group base_sha $MG_BASE_SHA not in checkout"; exit 1; }
git update-ref refs/remotes/origin/main "$MG_BASE_SHA"
{ echo "MG_PR_NUMBER=$pr"; echo "MG_BRANCH=${meta[0]}"; echo "MG_PR_TITLE=${meta[1]:-}"; } >> "$GITHUB_ENV"
echo "merge_group: PR #$pr, base $MG_BASE_SHA, tested $(git rev-parse HEAD)"
