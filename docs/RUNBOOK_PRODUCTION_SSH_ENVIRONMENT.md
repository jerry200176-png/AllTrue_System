# RUNBOOK — move Pi SSH secrets into the `production-ssh` environment

**Owner:** Founder (credential steps). **Risk:** R3 / T3 (production credentials + control plane).
**Decision:** Founder approved the direction on 2026-10-15. Enforced by control-plane-lint rule
**P2** (`scripts/ci/production-secret-triggers.mjs`, issue #4143).

**Why.** Repository-level secrets can be read by a workflow run on any ref. The deploy-trust review
found the Pi SSH key readable by 8,431 runs that were not on `main`. Secrets stored in an
environment are readable only by jobs that declare that environment. With a main-only branch policy,
GitHub refuses those jobs on any other ref.

**Secrets in scope:** `PI_SSH_KEY`, `PI_SSH_HOST`, `PI_SSH_USER`, `PI_HOST_KEY`, `PI_HOST`, `PI_USER`
(every `secrets.PI_*` that the workflows use for SSH).

## Order (do not reorder)

The environment and its secrets must exist **before** the PR that adds `environment: production-ssh`
merges. If the PR merges first, every SSH workflow fails, including `deploy.yml`.

Steps (a)–(c) → merge the PR → (d) → (e) → (f) → (g).

## (a) Create the environment

1. GitHub → repository **Settings → Environments → New environment** → name it `production-ssh`.
2. **Deployment branches and tags** → **Selected branches and tags** → add the rule `main`. Add no
   other branch or tag.
3. **Required reviewers: leave this OFF.** Scheduled jobs (`pi-health`, `slow-query-report`,
   `backup-restore-test`, `bug-auto-intake`, the `deploy.yml` schedule, and others) must keep running
   with nobody present. The branch policy is the control here, not a reviewer. Leave the wait timer
   at 0.

### Existing environments (documented exceptions)

A job can declare only one environment. Jobs that already use `production-activation` keep it:
`deploy.yml` `staged_principal_rotation`, the `pop-*.yml` repair jobs and the
`staff-multirole-activation.yml` `pilot_grant` / `enable` / `disable` jobs. For those jobs:

- add the **same** SSH secrets (step c) to `production-activation` as environment secrets; and
- set `production-activation` → Deployment branches and tags → Selected branches → `main` only, if
  it is not set already. Keep its required reviewer.

The lint (`DOCUMENTED_EXCEPTIONS`) accepts only `production-ssh` and `production-activation`.

## (b) Generate a new key pair (trusted machine, not the Pi, not a cloud agent)

```bash
ssh-keygen -t ed25519 -a 100 -N '' -C 'alltrue-gha-production-ssh-2026-10' -f ./gha_production_ssh
```

Append the **public** key (`gha_production_ssh.pub`) to `~admin/.ssh/authorized_keys` on the Pi.
Keep the old key in the file during the cutover, so that runs still using the repository-level
secret keep working until step (e).

## (c) Add the environment secrets

Never paste secret values into a chat, issue, PR or commit. Use the UI (Settings → Environments →
`production-ssh` → Add environment secret), or pass each value on stdin:

```bash
gh secret set PI_SSH_KEY  --env production-ssh --repo jerry200176-png/AllTrue_System < ./gha_production_ssh
gh secret set PI_SSH_HOST --env production-ssh --repo jerry200176-png/AllTrue_System   # prompts; same value as today
gh secret set PI_SSH_USER --env production-ssh --repo jerry200176-png/AllTrue_System   # prompts; same value as today
gh secret set PI_HOST_KEY --env production-ssh --repo jerry200176-png/AllTrue_System   # prompts; same pinned host key
gh secret set PI_HOST     --env production-ssh --repo jerry200176-png/AllTrue_System   # only if PI_HOST exists today
gh secret set PI_USER     --env production-ssh --repo jerry200176-png/AllTrue_System   # only if PI_USER exists today
```

`PI_SSH_HOST` / `PI_SSH_USER` keep the formats in G-006 (`pi.lifenet.com.tw` / `admin`, no `@`).
Repeat for `production-activation` (see the exceptions above). Then delete the local private key
file, or move it to offline storage.

## (d) Merge the PR and verify on `main`

1. Merge the PR that adds `environment: production-ssh`.
2. Run one read-only SSH workflow on `main`: Actions → **Pi health** (`pi-health.yml`) → Run
   workflow → branch `main`. It must succeed, and the run page must show the `production-ssh`
   deployment.
3. Optional negative check: run the same workflow on a non-main branch. The job must be refused
   with a branch-protection message for the environment.

## (e) Delete the repository-level secrets

Settings → Secrets and variables → Actions → **Repository secrets** → delete `PI_SSH_KEY`,
`PI_SSH_HOST`, `PI_SSH_USER`, `PI_HOST_KEY`, `PI_HOST` and `PI_USER`. Run `pi-health` on `main` again;
it must still pass, now reading only the environment secrets.

## (f) Remove the old key and prove it is refused

1. On the Pi, remove the old public key line from `~admin/.ssh/authorized_keys`.
2. From the trusted machine, try to connect with the old private key:
   `ssh -i <old-key> -o IdentitiesOnly=yes admin@pi.lifenet.com.tw true`. It must fail with
   `Permission denied (publickey)`.
3. Run `pi-health` on `main` once more. It must pass with the new key.

## (g) Read-only Pi integrity checklist

This step only reads. Record the results by ID or count in the tracking issue; never paste contents
or names.

- `authorized_keys`: every line is a key you can name; no unknown or extra keys.
- `last -a | head -50`, and the auth log (`journalctl -u ssh --since -30d` or `/var/log/auth.log*`):
  no unexpected source IPs or users.
- mtimes of `/home/admin/.env` (stat only, do not print it), the crontabs (`crontab -l` for admin,
  `/etc/cron*`), the rclone config, and `/etc/ssh/sshd_config*`: no change you cannot explain.
- MySQL `bind-address` is `127.0.0.1` (or the socket only).
- `SELECT user, host FROM mysql.user;` contains no `%` or unexpected remote hosts.

Anything unexpected → stop and treat it as an incident (`docs/OPERATIONS_RUNBOOK.md`).

## Rollback

Revert the PR, then add the repository-level secrets back (same names, current key). The
environment can stay; it is inert once no job declares it.
