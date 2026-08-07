# IAM / access review — 2026-08-07 (#888)

> Built from what's observable via `gh api`/existing docs in this session. **Flagging, not guessing**, where verification wasn't possible (e.g. LINE/Sentry console access — no API probe was run against those).

## GitHub

- Repo: `jerry200176-png/AllTrue_System`, single-owner account (`jerry200176-png`), no org/team structure observed (`gh api .../collaborators` not probed this pass — recommend a follow-up `gh api repos/.../collaborators` check).
- Branch protection: ruleset `main-protection` active — blocks deletion, non-fast-forward, requires PR + status checks. No bypass actors configured.
- Environments: `production-activation` exists (created 2026-07-31) but has **zero protection rules** (no required reviewers, no wait timer) — effectively a label, not a gate yet. Relevant to #875.
- Security features (as of this pass): Dependabot security updates **enabled**, secret scanning **enabled**, secret scanning push protection **enabled**, private vulnerability reporting **enabled** (turned on during this session), secret scanning non-provider patterns and validity checks **disabled**.

## Production host (Pi)

- SSH access: key-based (`admin@pi.lifenet.com.tw`), `admin` user. Password-based sudo is NOT available to this session's key (sudo commands prompted for a password and failed) — meaning at minimum this credential is not full-root-equivalent, which is good hygiene. Could not verify `sshd_config` (`PermitRootLogin`/`PasswordAuthentication`) directly — needs someone with sudo to confirm.
- No `ufw` installed, so no host firewall observed in the standard sense (see #887 for the full port-exposure finding — this is the more urgent half of the IAM/access picture: several ports listen on `0.0.0.0` with no visible access control layer in front of them).

## Third-party consoles (not verified this pass — flagging as unknown, not assuming either way)

- LINE Developers console, Sentry org access, Google Drive backup access (`docs/OPERATIONS_RUNBOOK.md §P`) — who has login access to each was not checked; no API surface available from this session to audit them. Recommend a manual owner-led review of "who has the password/2FA for each third-party console" — this genuinely can't be done by an agent without those credentials.

## Recommendations

1. Add at least one required reviewer to the `production-activation` GitHub Environment (#875) — currently a no-op gate.
2. Get sudo-capable access (or ask the person who has it) to confirm `sshd_config` hardening — this session's key couldn't check it.
3. Manual inventory of third-party console access (LINE/Sentry/Drive) — owner-only, not agent-executable.
