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
# Bind the fixed query principal to the actual serving vhost/socket/pool/worker.
# Resolver follows the existing mount workflow; explicit returns also fail closed
# when called from a conditional (where Bash errexit does not protect functions).
APP_ROOT=/home/admin/backend
APP_PUBLIC=/home/admin/backend/public
SITE_HINT=daan.lifenet.com.tw
awk_listen='/^[[:space:]]*listen[[:space:]]*=/{gsub(/;.*/,""); sub(/^[^=]*=/,""); gsub(/[[:space:]"]/,""); print; exit}'
awk_user='/^[[:space:]]*user[[:space:]]*=/{gsub(/;.*/,""); sub(/^[^=]*=/,""); gsub(/[[:space:]"]/,""); print; exit}'
awk_pool='/^\[[^][]+\]/{name=$0; gsub(/[\[\][:space:]]/,"",name); if (name!="" && name!="global") {print name; exit}}'

extract_listen() {
  conf="$1"
  if grep -qE 'proxy:unix:' "$conf" 2>/dev/null; then
    grep -oE 'proxy:unix:[^|\"[:space:]]+' "$conf" | head -1 | sed 's/^proxy:unix://'
  elif grep -qE 'unix:/[^|\"[:space:]]+\.sock' "$conf" 2>/dev/null; then
    grep -oE 'unix:/[^|\"[:space:]]+\.sock' "$conf" | head -1 | sed 's/^unix://'
  elif grep -qE 'fastcgi_pass[[:space:]]+[^;]+' "$conf" 2>/dev/null; then
    raw="$(grep -oE 'fastcgi_pass[[:space:]]+[^;]+' "$conf" | head -1 | awk '{print $2}')"
    echo "${raw#unix:}"
  fi
}

resolve_fpm() {
  listen_target=""
  for prefer in hint path; do
    for conf in /etc/apache2/sites-enabled/* /etc/apache2/conf-enabled/* \
                /etc/nginx/sites-enabled/* /etc/nginx/conf.d/*; do
      [ -r "$conf" ] || continue
      if [ "$prefer" = "hint" ]; then
        grep -qF "$SITE_HINT" "$conf" 2>/dev/null || continue
      else
        grep -qE "$APP_PUBLIC|$APP_ROOT" "$conf" 2>/dev/null || continue
      fi
      listen_target="$(extract_listen "$conf" || true)"
      [ -n "${listen_target:-}" ] && break
    done
    [ -n "${listen_target:-}" ] && break
  done
  test -n "${listen_target:-}" || return 1

  pool_user=""
  pool_name=""
  for conf in /etc/php/*/fpm/pool.d/*.conf /etc/php-fpm.d/*.conf; do
    [ -f "$conf" ] || continue
    plisten="$(awk "$awk_listen" "$conf" 2>/dev/null || true)"
    [ -n "${plisten:-}" ] || continue
    case "$plisten" in
      "$listen_target"|"/${listen_target#/}"|"unix:$listen_target") ;;
      *) continue ;;
    esac
    u="$(awk "$awk_user" "$conf" 2>/dev/null || true)"
    n="$(awk "$awk_pool" "$conf" 2>/dev/null || true)"
    [ -n "${n:-}" ] || n="$(basename "$conf" .conf)"
    if [ -n "${u:-}" ] && [ "$u" != "root" ]; then
      pool_user="$u"
      pool_name="$n"
      break
    fi
  done
  test -n "${pool_user:-}" || return 1
  test -n "${pool_name:-}" || return 1

  worker_pid=""
  while read -r pid args; do
    case "$args" in
      *"php-fpm: pool ${pool_name}"*|*"php-fpm: pool ${pool_name} "*)
        uid="$(awk '/^Uid:/{print $2}' "/proc/$pid/status" 2>/dev/null || true)"
        uname="$(getent passwd "${uid:-}" | cut -d: -f1 || true)"
        if [ "$uname" = "$pool_user" ]; then
          worker_pid="$pid"
          break
        fi
        ;;
    esac
  done < <(ps -eo pid=,args=)
  test -n "${worker_pid:-}" || return 1
  test -r "/proc/${worker_pid}/status" || return 1
  uid="$(awk '/^Uid:/{print $2}' "/proc/${worker_pid}/status")"
  gid="$(awk '/^Gid:/{print $2}' "/proc/${worker_pid}/status")"
  user="$(getent passwd "$uid" | cut -d: -f1)"
  test -n "${user:-}" || return 1
  test "$user" = "$pool_user" || return 1
  echo "fpm_serving pool=${pool_name} user=${pool_user} listen=${listen_target} worker_pid=${worker_pid}"
}

if ! resolve_fpm; then
  printf '%s\n' 'POLICY_UNKNOWN: app-serving FPM identity unresolved; no policy queries.' >&2
  exit 73
fi
if [ "$pool_name" != www ] || [ "$user" != www-data ] || [ "$uid" != 33 ] || [ "$gid" != 33 ]; then
  printf '%s\n' 'POLICY_UNKNOWN: serving identity differs from fixed query scope; no policy queries.' >&2
  exit 73
fi
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
