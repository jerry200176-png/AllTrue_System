---
owner: platform-security
status: active
issue: 887
---

# Production host hardening — quarterly checklist

## Why

`docs/SECURITY.md` records several hardening items verified once after the
Mirai incident (UFW, SSH password-auth disabled, fail2ban, unnecessary
services, Nginx security headers). There has been no repeatable checklist and
no periodic re-verification since, so drift after a config change (new open
port, a service re-enabled, a package upgrade resetting a default) would go
unnoticed. Refs #887.

## What this document is

A **checklist and cadence**, not an audit result. Every item below requires a
command run *on* the Pi (`admin@pi.lifenet.com.tw`) over SSH by a human — no
AI agent may SSH into production per this repo's R6 red line. **No audit has
been run against this checklist yet**; the first run is still owner-gated.

## Cadence

Run quarterly (Jan / Apr / Jul / Oct), or immediately after any change to
`nginx` config, firewall rules, or exposed services.

## Checklist

| # | Item | Command (run on Pi) | Pass condition |
|---|---|---|---|
| 1 | UFW enabled, default-deny inbound | `sudo ufw status verbose` | `Status: active`, default `deny (incoming)`, only expected ports (22, 80, 443) `ALLOW` |
| 2 | SSH password auth disabled | `sudo sshd -T \| grep -i passwordauthentication` | `passwordauthentication no` |
| 3 | SSH root login disabled | `sudo sshd -T \| grep -i permitrootlogin` | `permitrootlogin no` |
| 4 | fail2ban active, sshd jail enabled | `sudo fail2ban-client status sshd` | jail exists, `Currently banned` sane (not 0 forever = jail not triggering, not huge = under attack) |
| 5 | No unexpected listening services | `sudo ss -tulpn` | Only known services (nginx, sshd, mysql bound to localhost, app runtime) |
| 6 | No unexpected systemd services enabled | `systemctl list-unit-files --state=enabled` | Diff against last quarter's list; new entries need an owner |
| 7 | Nginx security headers present | `curl -sI https://<prod-domain>` | `X-Frame-Options`, `X-Content-Type-Options`, `Strict-Transport-Security` present |
| 8 | OS + security package updates | `sudo apt list --upgradable` | No pending security-flagged updates, or a dated exception noted below |

## Result log

| Date | Run by | Items failed | Follow-up issue |
|---|---|---|---|
| _(none yet — first run pending)_ | | | |

## What this document deliberately does not do

- Does not run the checks itself — that requires Pi SSH, which is owner-only
  (R6). An agent can prep this checklist and read a human-pasted result, not
  execute it.
- Does not cover application-layer security (auth, authz, input validation) —
  that's code review / #888 (IAM) / #890 (audit log) scope, not host hardening.

Refs #887.
