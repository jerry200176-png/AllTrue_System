#!/usr/bin/env bash
# Read-only host hardening audit (#887). Fed to the Pi over SSH by pi-health.yml; changes no host setting and needs
# no password: sudo is tried with -n only, and every check has a no-sudo fallback.
# The repo is public, so stdout (the run log) carries only the number of findings. Which checks failed and the port
# list go to the private ops channel (monitor-alert.sh notify), on Mondays only so an open finding nags weekly, not daily.
# A finding is a warning, not a red run: the fix (scripts/pi-host-hardening.sh) changes the host and needs its own go.
set -u
fails=0
detail=""
say() { detail="${detail}$1 $2; "; [ "$2" = PASS ] || fails=$((fails + 1)); }

if systemctl is-active --quiet ufw 2>/dev/null && grep -q '^ENABLED=yes' /etc/ufw/ufw.conf 2>/dev/null; then say ufw PASS; else say ufw FAIL; fi

# Effective sshd settings when readable; otherwise the config files in the order sshd reads them (first value wins).
conf="$(sudo -n sshd -T 2>/dev/null || sshd -T 2>/dev/null || cat /etc/ssh/sshd_config.d/*.conf /etc/ssh/sshd_config 2>/dev/null)"
first() { printf '%s\n' "$conf" | awk -v k="$1" 'tolower($1) == k { print tolower($2); exit }'; }
[ "$(first passwordauthentication)" = no ] && say ssh-password-off PASS || say ssh-password-off FAIL
case "$(first permitrootlogin)" in no | prohibit-password | without-password | '') say ssh-root-off PASS ;; *) say ssh-root-off FAIL ;; esac

if systemctl is-active --quiet fail2ban 2>/dev/null; then say fail2ban PASS; else say fail2ban FAIL; fi

ports="$(ss -tlnH 2>/dev/null | awk '{print $4}' | grep -vE '^(127\.|\[::1\])' | sed 's/.*://' | sort -un | tr '\n' ' ' | sed 's/ $//')"
extra="$(printf '%s\n' $ports | grep -vxE '22|80|443' | tr '\n' ' ' | sed 's/ $//')"
# An empty list cannot be right (sshd is serving this very connection), so it counts as a finding.
[ -n "$ports" ] && [ -z "$extra" ] && say only-22-80-443 PASS || say only-22-80-443 "FAIL (open: ${ports:-unreadable})"

echo "host-hardening-audit: ${fails} finding(s)"
[ "$fails" -eq 0 ] || echo "::warning::host hardening: ${fails} finding(s); details go to the ops channel on Mondays"
if [ "$fails" -gt 0 ] && [ "$(date +%u)" = 1 ] && [ -f /home/admin/scripts/monitor-alert.sh ]; then
  bash /home/admin/scripts/monitor-alert.sh notify "🔒 主機安全檢查：${fails} 項未通過。${detail}" >/dev/null 2>&1 || true
fi
