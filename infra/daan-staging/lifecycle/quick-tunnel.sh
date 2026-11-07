#!/usr/bin/env bash
# Interim public URL: Cloudflare quick tunnel (random *.trycloudflare.com, changes on restart).
# Fails closed: never starts unless the local listener is the gated staging nginx. systemd retries.
# Writes the current URL (not secret) to ~/alltrue-stage/state/public-url.
set -euo pipefail
source "$(cd "$(dirname "$0")" && pwd)/_common.sh"
OUT="${HOME}/alltrue-stage/state/public-url"; rm -f "${OUT}"
bash "$(dirname "$0")/gate-probe.sh"
B="http://127.0.0.1:${STAGING_PORT}"

# An existing ~/.cloudflared/config.yml (named-tunnel ingress) would turn every quick-tunnel request into 404: use an empty one.
: >"${HOME}/alltrue-stage/state/quick.yml"
exec "${HOME}/bin/cloudflared" --config "${HOME}/alltrue-stage/state/quick.yml" tunnel --url "${B}" --no-autoupdate 2>&1 \
  | while IFS= read -r line; do
      echo "${line}"
      if [[ "${line}" =~ (https://[a-z0-9-]+\.trycloudflare\.com) ]]; then echo "${BASH_REMATCH[1]}" >"${OUT}"; fi
    done
