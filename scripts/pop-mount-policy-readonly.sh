#!/usr/bin/env bash
# PROPOSED ONLY: no production execution without explicit diagnostic transport approval.
# Does not execute any sudo command except -l policy queries. Never reads file contents.
set -uo pipefail
export LC_ALL=C
printf 'observed_utc='; date -u +%FT%TZ
printf 'host='; hostname -f
id
if [ "$(id -un)" != admin ]; then
  printf '%s\n' 'STOP: identity differs from documented workflow account; no policy queries.' >&2
  exit 70
fi
printf '%s\n' '--- command resolution ---'
for c in sudo chown chmod runuser test namei stat getfacl; do
  resolved=$(type -P "$c" || true)
  printf '%s %s\n' "$c" "${resolved:-MISSING}"
  if [ -n "$resolved" ]; then readlink -f -- "$resolved"; fi
done
getent passwd admin www-data
getent group www-data
printf '%s\n' '--- fixed-path metadata only ---'
paths=(/home /home/admin /home/admin/backend /home/admin/backend/storage /home/admin/backend/storage/app /home/admin/backend/storage/app/private /home/admin/backend/storage/app/private/pop)
base=/home/admin/backend/storage/app/private/pop/reviewed-financial-cases.json
for p in "${paths[@]}" "$base" "$base.staging" "$base.prev"; do
  if [ -L "$p" ]; then
    printf 'SYMLINK_STOP %s\n' "$p" >&2
    exit 72
  fi
  stat -c '%F uid=%u gid=%g mode=%a links=%h path=%n' -- "$p" 2>&1 || true
  if command -v getfacl >/dev/null 2>&1; then getfacl -cp -- "$p" 2>&1 || true; fi
done
if command -v namei >/dev/null 2>&1; then namei -l -- "$base" 2>&1 || true; fi
printf '%s\n' '--- serving FPM identity metadata ---'
ps -eo pid=,uid=,gid=,args= | awk '/php-fpm: pool www$/ {print}'
printf '%s\n' '--- effective sudo policy, no password and no command execution ---'
sudo -n -ll 2>&1
policy_rc=$?
printf 'sudo_list_exit=%s\n' "$policy_rc"
if [ "$policy_rc" -ne 0 ]; then
  printf '%s\n' 'POLICY_UNKNOWN: list denied; stop, no grant inference and no retry.'
  exit 71
fi
query() {
  printf 'POLICY_QUERY'; printf ' %q' "$@"; printf '\n'
  sudo -n -l "$@" 2>&1
  printf 'query_exit=%s\n' "$?"
}
for target in "$base.staging" "$base"; do
  # Compare literal invocation, canonical binary and equivalent identity spelling.
  query chown 33:33 "$target"
  query /usr/bin/chown 33:33 "$target"
  query /usr/bin/chown www-data:www-data "$target"
  query chmod 640 "$target"
  query /usr/bin/chmod 640 "$target"
  query runuser -u www-data -- test -r "$target"
  query /usr/sbin/runuser -u www-data -- test -r "$target"
done
for p in "${paths[@]}"; do query runuser -u www-data -- test -x "$p"; done
printf '%s\n' '--- unrelated privileges: policy queries only, never execute ---'
query /bin/sh -c id
query /usr/bin/chown 33:33 /etc/passwd
query /usr/bin/chmod 644 "$base"
query /usr/sbin/runuser -u root -- /bin/sh
printf '%s\n' 'diagnostic_complete; no mount/data/permission mutation performed'
