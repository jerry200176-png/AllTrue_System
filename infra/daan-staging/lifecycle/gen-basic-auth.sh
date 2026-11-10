#!/usr/bin/env bash
# Create the staging gate secrets (idempotent; never prints secrets). All outside git, in ~/alltrue-stage/state/:
#   pw-founder     plaintext password of htpasswd user `founder` (mode 600) -> browser visits /__gate once
#   gate-secret    plaintext cookie value (mode 600) -> `Cookie: alltrue_staging_gate=<value>` for the preview proxy
#   htpasswd       hash of pw-founder (nginx workers read it)      gate.conf   nginx map with the secret (mode 600)
# Rotate: delete pw-founder and/or gate-secret, rerun, redeploy nginx.
set -euo pipefail
D="${HOME}/alltrue-stage/state"; mkdir -p "${D}"; umask 077
[[ -s "${D}/pw-founder" ]]  || openssl rand -base64 18 >"${D}/pw-founder"
[[ -s "${D}/gate-secret" ]] || openssl rand -hex 32 >"${D}/gate-secret"
rm -f "${D}/pw-preview-proxy"
printf 'founder:%s\n' "$(openssl passwd -apr1 -stdin <"${D}/pw-founder")" >"${D}/htpasswd"
chmod 644 "${D}/htpasswd"   # hash only; read by unprivileged nginx workers through the rootless bind mount
printf 'map_hash_bucket_size 128;\nmap $host $gate_secret { default "%s"; }\nmap $cookie_alltrue_staging_gate $gate_ok { default 0; "%s" 1; }\n' \
  "$(cat "${D}/gate-secret")" "$(cat "${D}/gate-secret")" >"${D}/gate.conf"
chmod 600 "${D}/pw-founder" "${D}/gate-secret" "${D}/gate.conf"
echo "GATE_OK files=${D}/{pw-founder,gate-secret}"
