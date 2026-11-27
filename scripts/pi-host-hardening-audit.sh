#!/usr/bin/env bash
# Read-only host hardening audit (#887). Fed to the Pi over SSH by pi-health.yml; changes nothing and needs no
# password: sudo is tried with -n only, and every check has a no-sudo fallback. Prints PASS/FAIL and port numbers
# (no addresses, users or secrets). A FAIL is a GitHub warning, not a red run: the fix (scripts/pi-host-hardening.sh)
# changes the host and needs its own go.
set -u
fails=0
say() { printf '%-18s %s\n' "$1" "$2"; [ "$2" = PASS ] || { fails=$((fails + 1)); echo "::warning::host hardening: $1 $2"; }; }

if systemctl is-active --quiet ufw 2>/dev/null && grep -q '^ENABLED=yes' /etc/ufw/ufw.conf 2>/dev/null; then say ufw PASS; else say ufw FAIL; fi

# Effective sshd settings when readable; otherwise the config files in the order sshd reads them (first value wins).
conf="$(sudo -n sshd -T 2>/dev/null || sshd -T 2>/dev/null || cat /etc/ssh/sshd_config.d/*.conf /etc/ssh/sshd_config 2>/dev/null)"
first() { printf '%s\n' "$conf" | awk -v k="$1" 'tolower($1) == k { print tolower($2); exit }'; }
[ "$(first passwordauthentication)" = no ] && say ssh-password-off PASS || say ssh-password-off FAIL
case "$(first permitrootlogin)" in no | prohibit-password | without-password | '') say ssh-root-off PASS ;; *) say ssh-root-off FAIL ;; esac

if systemctl is-active --quiet fail2ban 2>/dev/null; then say fail2ban PASS; else say fail2ban FAIL; fi

ports="$(ss -tlnH 2>/dev/null | awk '{print $4}' | grep -vE '^(127\.|\[::1\])' | sed 's/.*://' | sort -un | tr '\n' ' ' | sed 's/ $//')"
echo "open-ports         ${ports}"
extra="$(printf '%s\n' $ports | grep -vxE '22|80|443' | tr '\n' ' ' | sed 's/ $//')"
[ -z "$extra" ] && say only-22-80-443 PASS || say only-22-80-443 "FAIL (${extra})"

echo "host-hardening-audit: ${fails} finding(s)"
