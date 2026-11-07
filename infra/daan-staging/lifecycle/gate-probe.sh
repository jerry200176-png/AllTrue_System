#!/usr/bin/env bash
# Fail closed unless the local listener is the gated staging nginx. Used before any tunnel starts.
set -euo pipefail
source "$(cd "$(dirname "$0")" && pwd)/_common.sh"
B="http://127.0.0.1:${STAGING_PORT}"
[[ "$("${DOCKER_BIN}" port alltrue-stage-nginx 80/tcp 2>/dev/null | head -1)" == "127.0.0.1:${STAGING_PORT}" ]] \
  || { echo "REFUSE: ${STAGING_PORT} is not published by alltrue-stage-nginx on 127.0.0.1" >&2; exit 1; }
code() { curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -m 10 "$@" || true; }
[[ "$(code "${B}/")" == "302 "*"/__gate" ]] || { echo "REFUSE: / is not gated (expected 302 -> /__gate)" >&2; exit 1; }
[[ "$(code "${B}/api/v1/students")" == "401 "* ]] || { echo "REFUSE: /api/v1/students is not gated (expected 401)" >&2; exit 1; }
