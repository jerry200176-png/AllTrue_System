# RUNBOOK — move Pi SSH secrets into the `production-ssh` environment

**Owner:** Founder (credential steps). **Risk:** R3 / T3 (production credentials + control plane).
**Decision:** Founder approved the direction on 2026-10-15. Enforced by control-plane-lint rule
**P2** (`scripts/ci/production-ssh-environment.mjs`, issue #4143).

**Why.** Repository-level secrets can be read by a workflow run on any ref. The deploy-trust review
found the Pi SSH key readable by 8,431 runs that were not on `main`. Secrets stored in an
environment are readable only by jobs that declare that environment. With a main-only branch policy,
GitHub refuses those jobs on any other ref.

**Secrets in scope:** `PI_SSH_KEY`, `PI_SSH_HOST`, `PI_SSH_USER`, `PI_HOST_KEY`, `PI_HOST`, `PI_USER`
(every `secrets.PI_*` that the workflows use for SSH).

## Secret formats (verified against every consumer, 2026-10-15)

| Secret | Exact format | Consumer that fixes the format |
|---|---|---|
| `PI_SSH_KEY` | **base64 text** of the whole OpenSSH private-key file (one line, no `-----BEGIN` text). | All 83 decode sites in 66 workflows run `echo "$PI_SSH_KEY" \| tr -d '[:space:]' \| base64 -d > <keyfile>` (e.g. `deploy.yml`, `pi-health.yml` "Setup SSH"). No consumer reads it raw. Raw key text makes `base64 -d` fail (`base64: invalid input`). |
| `PI_HOST_KEY` | **raw `known_hosts` lines** (plain text, NOT base64), one entry per line, covering the host, e.g. `<host> ssh-ed25519 AAAA…`. Clear or hashed host names both work. | `.github/actions/production-ssh-trust/setup.sh` (83 `host-key:` callers). It writes the value as-is, requires every line to parse with `ssh-keygen -lf` and at least one entry to match the host. Other uses are only `test -n` presence checks. |
| `PI_SSH_HOST`, `PI_HOST` | Bare hostname `pi.lifenet.com.tw`: no `user@`, no port, no scheme (must match `^[A-Za-z0-9][A-Za-z0-9._:-]*$`). | `production-ssh-trust` host check; `ssh user@host` in each workflow. G-006. |
| `PI_SSH_USER`, `PI_USER` | Bare user name `admin`. | `ssh user@host`. G-006. |

`PI_HOST` / `PI_USER` are **required** in the environment: `pi-health.yml` reads only these two. If they
are missing, its "Check Pi secrets" step prints a `Pi secrets 未設定` notice, skips every SSH step and still
ends green (see step d).

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
`deploy.yml` `staged_principal_rotation`, the `pop-*.yml` jobs that still read the SSH secrets under
the reviewer (Telegram rotation, financial mount, and any repair job not yet split into a secret-free
`founder-approval` job on `production-activation` plus an SSH job on `production-ssh`, Founder
2026-10-28 A) and the `staff-multirole-activation.yml` `pilot_grant` / `enable` / `disable` jobs. For those jobs:

- add the **same** SSH secrets (step c) to `production-activation` as environment secrets; and
- set `production-activation` → Deployment branches and tags → Selected branches → `main` only, if
  it is not set already. Keep its required reviewer.

The lint (`DOCUMENTED_EXCEPTIONS`) accepts only `production-ssh` and `production-activation`.

## (b) Generate a new key pair (trusted machine, not the Pi, not a cloud agent)

```bash
ssh-keygen -t ed25519 -a 100 -N '' -C 'alltrue-gha-production-ssh-2026-10' -f ~/.ssh/alltrue_pi_ed25519
```

Append the **public** key (`~/.ssh/alltrue_pi_ed25519.pub`) to `~admin/.ssh/authorized_keys` on the Pi.
Keep the old key in the file during the cutover, so that runs still using the repository-level
secret keep working until step (e).

## (c) Add the environment secrets

Never paste secret values into a chat, issue, PR or commit, and never write the base64 form to a file.
Pipe each value on stdin (`gh secret set` reads stdin when `--body` is omitted):

```bash
REPO=jerry200176-png/AllTrue_System
KEY=~/.ssh/alltrue_pi_ed25519

# 0. Prove the encoding round-trips before uploading (prints only "format-ok"; no value, no file):
base64 -w0 < "$KEY" | base64 -d | cmp -s - "$KEY" && echo format-ok    # macOS: base64 -i "$KEY" | tr -d '\n' | base64 -D | cmp -s - "$KEY" && echo format-ok

# 1. PI_SSH_KEY = base64 of the private-key file, one line.
base64 -w0 < "$KEY" | gh secret set PI_SSH_KEY --env production-ssh --repo "$REPO"
#    macOS: base64 -i "$KEY" | tr -d '\n' | gh secret set PI_SSH_KEY --env production-ssh --repo "$REPO"

# 2. The rest are entered at the prompt, same values as today (formats in the table above).
gh secret set PI_SSH_HOST --env production-ssh --repo "$REPO"   # pi.lifenet.com.tw
gh secret set PI_SSH_USER --env production-ssh --repo "$REPO"   # admin
gh secret set PI_HOST     --env production-ssh --repo "$REPO"   # pi.lifenet.com.tw (required by pi-health)
gh secret set PI_USER     --env production-ssh --repo "$REPO"   # admin (required by pi-health)
gh secret set PI_HOST_KEY --env production-ssh --repo "$REPO" < <(ssh-keygen -F pi.lifenet.com.tw -f ~/.ssh/known_hosts | grep -v '^#')
#    PI_HOST_KEY is raw known_hosts text, NOT base64. Use only a host entry you already verified;
#    otherwise enter the Founder-approved pinned entries at the prompt.
```

**Same for `production-activation`:** run steps 1–2 again with `--env production-activation`.

**UI route** (Settings → Environments → `<env>` → Add environment secret): for `PI_SSH_KEY`, paste the
**base64 text**, never the `-----BEGIN OPENSSH PRIVATE KEY-----` file contents. Produce it with
`base64 -w0 < "$KEY" | xclip -selection clipboard` (macOS: `base64 -i "$KEY" | tr -d '\n' | pbcopy`)
and clear the clipboard afterwards. `PI_HOST_KEY` is pasted as the raw `known_hosts` lines.

Keep the private key file until step (f) passes, then delete it or move it to offline storage.

An environment secret overrides the repository secret of the same name for jobs that declare the
environment. A wrong format therefore breaks every SSH workflow as soon as the PR merges, not only
after step (e). Recovery: set the secret again in the right format; no revert is needed.

## (d) Merge the PR and verify on `main` (mandatory, before step e)

1. Merge the PR that adds `environment: production-ssh`.
2. **Format proof (runtime).** Run `gh workflow run pi-health.yml --ref main --repo "$REPO"` (or Actions →
   **Pi Health Monitor** → Run workflow → `main`). A green run is not enough. The run counts only if all
   of these hold:
   - the job header shows the `production-ssh` deployment;
   - "Configure pinned production SSH trust" logs `Pinned production host-key validation: entries=N parsed=N matched=M` with M ≥ 1 (`PI_HOST_KEY` and host are right);
   - "Check Pi secrets" does **not** contain the `Pi secrets 未設定` notice;
   - "Setup SSH" and "Run health checks on Pi" show a green check, **not** the grey skipped icon ("Setup SSH" is where a raw, non-base64 `PI_SSH_KEY` fails with `base64: invalid input`);
   - the "Run health checks on Pi" log (it runs `ssh -v`) contains `Authenticated to *** (` … `using "publickey"` and the health output (for example `磁碟使用率：`).
   If any SSH step was skipped or failed, stop here, fix the secret, and rerun. Do not go on to step (e).
3. **Non-main refusal proof for `production-ssh` (mandatory).** This proves the branch policy, not
   just the secrets. Without it, an environment created without the main-only rule would pass step 2 and
   step (e) would move the only key into an unrestricted environment.
   ```bash
   git fetch origin main
   git push origin origin/main:refs/heads/ci/production-ssh-refusal-check   # same revision as merged main
   gh workflow run pi-health.yml --ref ci/production-ssh-refusal-check --repo "$REPO"
   ```
   Expected: the run fails immediately. The `Pi System Health` job has **no steps** and its annotation reads
   `Branch "ci/production-ssh-refusal-check" is not allowed to deploy to production-ssh due to environment protection rules.`
   No `production-ssh` deployment is created for that ref. If any step ran (for example "Checkout"),
   the policy is wrong: stop, fix (a).2, and repeat.
4. **Non-main refusal proof for `production-activation` (mandatory).** From the same test branch:
   ```bash
   gh workflow run staff-multirole-activation.yml --ref ci/production-ssh-refusal-check --repo "$REPO" \
     -f action=disable -f confirm=DISABLE_STAFF_MULTI_ROLE_V1
   ```
   The `guard` job passes. Expected: the `disable` job is refused with
   `Branch "ci/production-ssh-refusal-check" is not allowed to deploy to production-activation due to environment protection rules.`
   and runs no steps. If it shows **"Waiting for review"** instead, the policy is missing. **Reject** the
   review (never approve), fix the policy, and repeat.
5. Read-only configuration check (no secret values), for each environment:
   `gh api repos/$REPO/environments/production-ssh --jq .deployment_branch_policy` must print
   `{"custom_branch_policies":true,"protected_branches":false}`, and
   `gh api repos/$REPO/environments/production-ssh/deployment-branch-policies --jq '[.branch_policies[]|{name,type}]'`
   must print only `[{"name":"main","type":"branch"}]`. Repeat with `production-activation`.
6. Delete the test branch: `git push origin --delete ci/production-ssh-refusal-check` (or the branch
   page → Delete). Record the run URLs from steps 2–4 (IDs only) in the tracking issue.

## (e) Delete the repository-level secrets

Settings → Secrets and variables → Actions → **Repository secrets** → delete `PI_SSH_KEY`,
`PI_SSH_HOST`, `PI_SSH_USER`, `PI_HOST_KEY`, `PI_HOST` and `PI_USER`. Run `pi-health` on `main` again;
it must pass under the same conditions as step (d).2 (SSH steps executed, `Authenticated to` in the log),
now reading only the environment secrets.

## (f) Remove the old key and prove it is refused

1. On the Pi, remove the old public key line from `~admin/.ssh/authorized_keys`.
2. From the trusted machine, try to connect with the old private key:
   `ssh -i <old-key> -o IdentitiesOnly=yes admin@pi.lifenet.com.tw true`. It must fail with
   `Permission denied (publickey)`.
3. Run `pi-health` on `main` once more. It must pass with the new key, with the SSH steps executed as
   in step (d).2.

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

Order matters. After step (e) no repository-level key exists, so reverting the workflows first would
leave `main` on workflows that read repository secrets that are gone. The revert's own CI completion
then starts `deploy.yml` without credentials.

1. **Restore the repository-level secrets first** (Settings → Secrets and variables → Actions →
   Repository secrets), with the same names and the formats in the table above, using the **current**
   key (`base64 -w0 < "$KEY" | gh secret set PI_SSH_KEY --repo "$REPO"`; others at the prompt). Keep the
   environment secrets as they are.
2. Confirm with `gh secret list --repo "$REPO"` (it shows names and update times only, never values)
   that all six names are present at repository level. Until the revert, every SSH job still declares
   an environment, so the runtime check comes in step 4.
3. Revert the PR (merge the revert through the normal PR path).
4. Run `pi-health` on `main` and apply the step (d).2 checks (SSH steps executed, `Authenticated to` in
   the log). The environment can stay; it is inert once no job declares it.

Before step (e) the repository secrets still exist, so a rollback is just step 3 + step 4.
