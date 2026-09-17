#!/usr/bin/env bash
# Fail closed if staging runtime mutated the host Git checkout.
# STAGING_HOST_CHECKOUT_MUTATION regression guard.
set -euo pipefail
source "$(cd "$(dirname "$0")" && pwd)/_common.sh"

cd "${REPO_ROOT}"
STATUS="$(git status --short)"
if [[ -n "${STATUS}" ]]; then
  echo "ERROR: host checkout is dirty after staging lifecycle (forbidden):" >&2
  echo "${STATUS}" >&2
  echo "STAGING_HOST_CHECKOUT_MUTATION — container must not bind-mount writable host Git paths." >&2
  exit 1
fi

# Tracked file modes must remain as committed (no www-data chmod bleed).
# sample critical paths that previously mutated:
for path in backend/bootstrap/cache/.gitignore; do
  if [[ -e "${path}" ]]; then
    owner="$(stat -c '%U:%G' "${path}" 2>/dev/null || stat -f '%Su:%Sg' "${path}")"
    if [[ "${owner}" == "www-data:www-data" ]]; then
      echo "ERROR: ${path} owned by www-data (host checkout mutation)" >&2
      exit 1
    fi
  fi
done

echo "HOST_CHECKOUT_CLEAN"
