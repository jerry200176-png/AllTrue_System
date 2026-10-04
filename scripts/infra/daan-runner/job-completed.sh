#!/usr/bin/env bash
# Runner job-completed hook (runs as ghrunner). Deletes only what THIS job created:
#   - Docker containers / networks / volumes that were not in the job-started baseline,
#     and only in ghrunner's rootless daemon (it cannot see or touch the system Docker daemon).
#   - The job's own workspace, and only if it is under the runner's _work directory.
set -u
STATE="${RUNNER_TEMP:-/tmp}/daan-job-baseline"
[ -d "$STATE" ] || exit 0

new_items() { comm -13 <(sort "$1" 2>/dev/null) <(sort); }

docker ps -aq --no-trunc 2>/dev/null | new_items "$STATE/containers" | xargs -r docker rm -f >/dev/null 2>&1 || true
docker network ls -q --no-trunc 2>/dev/null | new_items "$STATE/networks" | xargs -r docker network rm >/dev/null 2>&1 || true
docker volume ls -q 2>/dev/null | new_items "$STATE/volumes" | xargs -r docker volume rm >/dev/null 2>&1 || true

WS="$(cat "$STATE/workspace" 2>/dev/null)"
case "$WS" in
  /opt/actions-runner/_work/*) find "$WS" -mindepth 1 -delete 2>/dev/null || true ;;
esac
rm -rf "$STATE"
