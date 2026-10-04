#!/usr/bin/env bash
# Runner job-started hook (runs as ghrunner). Records what already exists in ghrunner's rootless Docker
# so job-completed.sh removes ONLY resources this job created.
set -u
STATE="${RUNNER_TEMP:-/tmp}/daan-job-baseline"
mkdir -p "$STATE"
docker ps -aq --no-trunc > "$STATE/containers" 2>/dev/null || true
docker network ls -q --no-trunc > "$STATE/networks" 2>/dev/null || true
docker volume ls -q > "$STATE/volumes" 2>/dev/null || true
echo "${GITHUB_WORKSPACE:-}" > "$STATE/workspace"
