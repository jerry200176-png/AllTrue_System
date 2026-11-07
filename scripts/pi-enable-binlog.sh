#!/usr/bin/env bash
# Enable MariaDB binlog on the Pi for point-in-time recovery (#881). Founder runs it; agents never do.
#   sudo bash /home/admin/scripts/pi-enable-binlog.sh            # dry run: checks only, changes nothing
#   sudo bash /home/admin/scripts/pi-enable-binlog.sh --apply    # install config, restart MariaDB (~10 s), verify; any failure removes the config again
#   sudo bash /home/admin/scripts/pi-enable-binlog.sh --rollback # remove config, restart, verify binlog OFF
set -euo pipefail
MODE="${1:-}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$HERE/ops/mariadb/60-alltrue-binlog.cnf"
DST=/etc/mysql/mariadb.conf.d/60-alltrue-binlog.cnf
MIN_FREE_MB=3072
say() { printf '%s\n' "$*"; }
die() { say "STOP: $*"; exit 1; }

[ "$(id -u)" = 0 ] || die "run with sudo"
command -v mariadb >/dev/null || die "mariadb client not found"
q() { mariadb -N -B -e "$1"; }   # root via unix socket
datadir="$(q 'SELECT @@datadir')"
free_mb=$(df -Pm "$datadir" | awk 'NR==2{print $4}')
say "MariaDB $(q 'SELECT VERSION()') | log_bin=$(q 'SELECT @@log_bin') | datadir=$datadir | free=${free_mb} MB"

restart_and_wait() {   # 0 = MariaDB answers again within 60 s
  systemctl restart mariadb || return 1
  for _ in $(seq 1 60); do q 'SELECT 1' >/dev/null 2>&1 && return 0; sleep 1; done
  return 1
}
undo_and_stop() {   # any --apply failure: take the config out again and bring MariaDB back as before
  rm -f "$DST"
  restart_and_wait || die "$1; config removed but MariaDB is still down: run 'systemctl status mariadb' and 'journalctl -u mariadb -n 50'"
  die "$1; config removed, MariaDB is back without binlog"
}

case "$MODE" in
  "")
    [ -f "$SRC" ] || die "missing $SRC (deploy first)"
    [ "$free_mb" -ge "$MIN_FREE_MB" ] || die "less than ${MIN_FREE_MB} MB free"
    [ -f "$DST" ] && say "config already installed: $DST"
    say "DRY RUN OK. Next: sudo bash $0 --apply   (quiet time; MariaDB restarts for ~10 s)"
    ;;
  --apply)
    [ -f "$SRC" ] || die "missing $SRC (deploy first)"
    [ "$free_mb" -ge "$MIN_FREE_MB" ] || die "less than ${MIN_FREE_MB} MB free"
    install -m 0644 -o root -g root "$SRC" "$DST"
    restart_and_wait || undo_and_stop "MariaDB did not restart with the binlog config"
    [ "$(q 'SELECT @@log_bin' 2>/dev/null)" = 1 ] || undo_and_stop "binlog did not turn on"
    say "binlog ON | format=$(q 'SELECT @@binlog_format') | expire=$(q 'SELECT @@binlog_expire_logs_seconds') s"
    q 'SHOW BINARY LOGS' | tail -1
    curl -fsS -o /dev/null https://daan.lifenet.com.tw/api/v1/health && say "app health OK" || say "WARN: app health check failed; run --rollback if the site is down"
    say "DONE. The next sixhour backup records the binlog position automatically."
    ;;
  --rollback)
    rm -f "$DST"
    restart_and_wait || die "MariaDB did not come back: run 'systemctl status mariadb'"
    say "binlog log_bin=$(q 'SELECT @@log_bin') (0 = off). Old binlog files stay in $datadir until removed by hand."
    ;;
  *) die "unknown option: $MODE" ;;
esac
