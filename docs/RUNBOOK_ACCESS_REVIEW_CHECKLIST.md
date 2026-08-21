---
owner: platform-security
status: active
issue: 888
---

# IAM / access review — quarterly checklist

## Why

There is no periodic review confirming that app roles, GitHub access, Pi SSH
keys, and third-party platform access (LINE, Sentry, UptimeRobot) match who
actually still needs them. A departed collaborator or an unused token is
invisible until something goes wrong. Refs #888.

## Cadence

Run quarterly (Jan / Apr / Jul / Oct), or immediately after anyone leaves the
team or changes role.

## 1. GitHub collaborators — first run, 2026-08-21

Pulled via `gh api repos/jerry200176-png/AllTrue_System/collaborators`:

| Login | Admin | Push | Owner confirmed still needed? |
|---|---|---|---|
| jerry200176-png | yes | yes | yes — repo owner |
| MonkeyJeng | no | yes | _(needs owner confirmation)_ |
| captain-balung | no | yes | _(needs owner confirmation)_ |

Also check org-level tokens/PATs and GitHub Actions secrets under
Settings → Secrets, and any fine-grained PAT installations — not queryable
via `gh api` from an agent context; owner must check the Settings UI.

## 2. App roles (super_admin / director / teacher)

Not pulled here — requires a production DB query
(`SELECT id, type, ... FROM User WHERE type IN ('super_admin', ...)`), which
is owner-only per R6. Run via the existing bug-detail-dump-style read-only
path or a human DB session, list every `super_admin` account and its last
login, and confirm each still needs that level.

## 3. Pi SSH keys

`cat ~/.ssh/authorized_keys` on the Pi (owner-only, R6). List each key's
comment/fingerprint and confirm an owner for each.

## 4. Third-party platform access (LINE, Sentry, UptimeRobot)

No API queried here — check each platform's team/member page manually and
list members + last-active date.

## Result log

| Date | Run by | High-priv accounts reviewed | Removed / flagged |
|---|---|---|---|
| 2026-08-21 | Claude (GitHub collaborators only) | 3 GitHub collaborators | none removed — pending owner confirmation above |

## What this document deliberately does not do

- Does not remove anyone's access — flags for owner decision only.
- Does not cover items 2–4 with live data this run — those need Pi/DB/platform
  access an agent doesn't have from this context. First full run is still
  owner-gated.

Refs #888.
