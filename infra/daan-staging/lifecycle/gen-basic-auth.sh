#!/usr/bin/env bash
# Create the nginx basic-auth file (idempotent; never prints secrets). Outside git, under ~/alltrue-stage/state/:
#   htpasswd (hashes, read by nginx)   pw-founder, pw-preview-proxy (plaintext, mode 600)
# Rotate: delete the files and rerun, then redeploy nginx.
set -euo pipefail
D="${HOME}/alltrue-stage/state"; mkdir -p "${D}"; umask 077
for u in founder preview-proxy; do
  [[ -s "${D}/pw-${u}" ]] || openssl rand -base64 18 >"${D}/pw-${u}"
done
: >"${D}/htpasswd.new"
for u in founder preview-proxy; do
  printf '%s:%s\n' "${u}" "$(openssl passwd -apr1 -stdin <"${D}/pw-${u}")" >>"${D}/htpasswd.new"
done
mv "${D}/htpasswd.new" "${D}/htpasswd"
# Hashes only; container-root (rootless) must read it through the bind mount, nginx workers run as another uid.
chmod 644 "${D}/htpasswd"
echo "BASIC_AUTH_OK users=founder,preview-proxy files=${D}/pw-{founder,preview-proxy}"
