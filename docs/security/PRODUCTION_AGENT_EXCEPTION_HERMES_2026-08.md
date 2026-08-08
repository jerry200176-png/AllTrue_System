---
owner: jerry (CEO)
service_owner: colleague co-maintaining AllTrue (identity to be recorded)
status: temporary-exception
approved_at: 2026-08-09
review_due: 2026-08-23
review_cycle: 14 days
issue: 1676
---

# Hermes production-agent exception

> This is a governance record for the Founder-approved temporary exception in
> [Issue #1676](https://github.com/jerry200176-png/AllTrue_System/issues/1676).
> It does not authorize direct production edits, credential disclosure,
> credential rotation, service stop, or a bypass of the WSL2 → PR → CI →
> deploy path.

## Decision and boundary

On 2026-08-09 the Founder approved option A: Hermes may remain on the
production Pi temporarily because a colleague actively uses it. The exception
is time-bounded and must be re-confirmed by 2026-08-23. There is no automatic
stop in this record; any stop, removal, credential revocation, or systemd
change requires an explicit execution plan and the appropriate owner approval.

While the exception is active:

- AllTrue code, configuration, database, and production-file changes still
  follow WSL2 → feature branch → PR → CI → automatic deploy.
- Hermes must not be treated as an approved AllTrue deployment path.
- No capability is considered approved merely because a tool or config key is
  present. Code execution, browser automation, delegation, terminal access,
  Git operations, production-file writes, SSH, and outbound messaging require
  an explicit capability inventory from the service owner.
- Secrets remain on the Pi. This audit did not read, copy, print, or rotate
  `/home/admin/.hermes/.env`, `auth.json`, or session files.

## Live read-only evidence

Collected 2026-08-09 from `pi.lifenet.com.tw` without changing the host:

| Area | Observed | Interpretation / boundary |
|---|---|---|
| Service | `hermes-gateway.service` is `enabled`, `active (running)`, `Restart=always` | Persistent user service; not an idle installation |
| Runtime | `/home/admin/.hermes/hermes-agent/venv/bin/python -m hermes_cli.main gateway run --replace` | Runs the Hermes gateway as the `admin` user |
| Persistence | `loginctl`: `Linger=yes`; user manager active | Survives logout and reboot |
| Service file | `/home/admin/.config/systemd/user/hermes-gateway.service` | User-level unit, outside AllTrue deploy governance |
| Identity | UID 1002 `admin`; groups include `www-data` and `users` | Broader host reach than a dedicated least-privilege account |
| Files | `.hermes` mode `700`; `.env` and auth/session files observed as owner-only | Basic file permissions are correct; secret contents were not inspected |
| Routines | `/home/admin/.hermes/cron/jobs.json` contained 0 routine entries at audit time | No Hermes cron routines observed; gateway persistence still exists |
| Network | No Hermes/python TCP listener appeared in `ss -lntup` at audit time | Does not prove that outbound messaging, webhooks, or future listeners are impossible |
| Sandboxing | `NoNewPrivileges=no`, `PrivateTmp=no`, `PrivateDevices=no`, `ProtectHome=no`, `ProtectSystem=no`; no address-family or syscall restriction | High-priority hardening gap |
| Runtime warning | Hermes logged that embedded SQLite 3.40.1 is vulnerable to the WAL-reset corruption bug and fell back to `journal_mode=DELETE` | Upgrade/remediation is needed for Hermes state integrity; this is separate from AllTrue MySQL |

The config contains control-surface sections for CLI, browser, code
execution, delegation, terminal, skills, and multiple messaging/platform
toolsets. Their effective enabled values were deliberately not copied into
this record; the colleague must provide the capability inventory without
exposing credentials or message content.

## Required owner / colleague confirmation

- [ ] Record the service owner's identity and intended Hermes routines.
- [ ] Confirm which tools are enabled and whether any can edit code, push Git,
      SSH, write `/home/admin`, access AllTrue secrets, or send messages.
- [ ] Confirm the exact credential names and minimum scopes required; rotate or
      revoke anything not required. Do not paste secret values into GitHub.
- [ ] Confirm whether the gateway needs to remain on the production Pi or can
      move to a non-production host.

## Hardening plan (follow-up PR / approved host operation)

1. Run under a dedicated non-deploy service account with no `www-data` group,
   no AllTrue repository write path, and only the minimum state directory.
2. Add a reviewed systemd sandbox: `NoNewPrivileges=yes`, private temporary
   and device namespaces, protected home/system paths, restricted address
   families, and an explicit writable-path allowlist.
3. Upgrade Hermes' embedded SQLite runtime or apply the vendor-supported repair
   path, then verify state integrity without touching AllTrue data.
4. Reduce enabled channels/tools to the documented use case and add a
   read-only service/version/config-fingerprint check to the quarterly host
   review. Fingerprints must never include secret values.
5. Re-audit by 2026-08-23. If the inventory or owner confirmation is missing,
   escalate to the Founder before extending the exception.

## Evidence and non-actions

- The AllTrue production deploy and read-only acceptance remained GREEN before
  this record was prepared.
- No Hermes service, systemd unit, credential, Pi file, database, or network
  rule was changed by this audit.
- This document is not evidence that Hermes is safe; it is the exception,
  evidence boundary, and follow-up contract needed to make the risk visible.
