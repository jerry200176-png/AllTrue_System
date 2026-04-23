#!/usr/bin/env bash
set -euo pipefail

# Branch hygiene tool (safe by default).
# - Default mode: dry-run (no deletion)
# - Apply mode: delete merged branches except protected patterns
#
# Usage:
#   ./scripts/branch-hygiene.sh
#   ./scripts/branch-hygiene.sh --apply
#   ./scripts/branch-hygiene.sh --apply --delete-backup

APPLY=false
DELETE_BACKUP=false
BASE_BRANCH="origin/main"

for arg in "$@"; do
  case "$arg" in
    --apply) APPLY=true ;;
    --delete-backup) DELETE_BACKUP=true ;;
    --base=*) BASE_BRANCH="${arg#*=}" ;;
    *)
      echo "Unknown arg: $arg"
      echo "Usage: $0 [--apply] [--delete-backup] [--base=origin/main]"
      exit 1
      ;;
  esac
done

is_protected_branch() {
  local b="$1"

  # Never touch key branches.
  if [[ "$b" == "main" || "$b" == "master" || "$b" == "develop" || "$b" == "dev" ]]; then
    return 0
  fi

  # Backup branches are protected unless explicitly requested.
  if [[ "$b" == backup-* && "$DELETE_BACKUP" != true ]]; then
    return 0
  fi

  return 1
}

echo "== Branch hygiene start =="
echo "Mode: $([[ "$APPLY" == true ]] && echo "APPLY" || echo "DRY-RUN")"
echo "Base branch: $BASE_BRANCH"
echo "Delete backup branches: $DELETE_BACKUP"
echo

git fetch --prune

CURRENT_BRANCH="$(git rev-parse --abbrev-ref HEAD)"
echo "Current branch: $CURRENT_BRANCH"
echo

echo "Checking local merged branches..."
mapfile -t LOCAL_MERGED < <(git branch --merged "$BASE_BRANCH" | sed 's/^[* ]*//')

LOCAL_DELETE=()
for b in "${LOCAL_MERGED[@]}"; do
  [[ -z "$b" ]] && continue
  if is_protected_branch "$b"; then
    continue
  fi
  LOCAL_DELETE+=("$b")
done

if [[ "${#LOCAL_DELETE[@]}" -eq 0 ]]; then
  echo "- No local merged branches eligible for deletion."
else
  printf -- "- Local merged branches eligible: %s\n" "${LOCAL_DELETE[@]}"
fi
echo

echo "Checking remote merged branches..."
mapfile -t REMOTE_MERGED < <(git branch -r --merged "$BASE_BRANCH" | sed 's/^[ ]*//' | sed 's#^origin/##' | grep -v '^HEAD$' || true)

REMOTE_DELETE=()
for b in "${REMOTE_MERGED[@]}"; do
  [[ -z "$b" ]] && continue
  if is_protected_branch "$b"; then
    continue
  fi
  REMOTE_DELETE+=("$b")
done

if [[ "${#REMOTE_DELETE[@]}" -eq 0 ]]; then
  echo "- No remote merged branches eligible for deletion."
else
  printf -- "- Remote merged branches eligible: %s\n" "${REMOTE_DELETE[@]}"
fi
echo

if [[ "$APPLY" != true ]]; then
  echo "Dry-run complete. No branch deleted."
  echo "Run with --apply to execute deletions."
  exit 0
fi

if [[ "$CURRENT_BRANCH" != "main" ]]; then
  echo "Safety stop: checkout main before apply mode."
  exit 1
fi

for b in "${LOCAL_DELETE[@]}"; do
  git branch -d "$b"
done

for b in "${REMOTE_DELETE[@]}"; do
  git push origin --delete "$b"
done

echo
echo "Apply complete."
