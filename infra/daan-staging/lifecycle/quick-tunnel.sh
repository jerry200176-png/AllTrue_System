#!/usr/bin/env bash
# Interim public URL: Cloudflare quick tunnel (random *.trycloudflare.com, changes on restart).
# Gated by the nginx cookie gate. Writes the current URL (not secret) to ~/alltrue-stage/state/public-url.
set -euo pipefail
OUT="${HOME}/alltrue-stage/state/public-url"; rm -f "${OUT}"
exec "${HOME}/bin/cloudflared" tunnel --url "http://127.0.0.1:${STAGING_HTTP_PORT:-18080}" --no-autoupdate 2>&1 \
  | while IFS= read -r line; do
      echo "${line}"
      if [[ "${line}" =~ (https://[a-z0-9-]+\.trycloudflare\.com) ]]; then echo "${BASH_REMATCH[1]}" >"${OUT}"; fi
    done
