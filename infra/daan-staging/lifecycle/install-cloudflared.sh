#!/usr/bin/env bash
# Install pinned cloudflared (release >7 days old, sha256 pinned from the GitHub release digest) into ~/bin.
set -euo pipefail
VER=2026.10.5   # published 2026-10-27
SHA256=e1bc0cf084fd9e590554fe769a86b1db46701c3f847a43d902f7b974a4e16298
mkdir -p "${HOME}/bin"; TMP="$(mktemp)"; trap 'rm -f "${TMP}"' EXIT
curl -fsSL -o "${TMP}" "https://github.com/cloudflare/cloudflared/releases/download/${VER}/cloudflared-linux-amd64"
echo "${SHA256}  ${TMP}" | sha256sum -c -
install -m 0755 "${TMP}" "${HOME}/bin/cloudflared"
"${HOME}/bin/cloudflared" --version
