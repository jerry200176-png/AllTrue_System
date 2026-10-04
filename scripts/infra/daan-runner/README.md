# daan-ai self-hosted CI runner (isolated)

Why: the repo went private on 2026-10-04 (PII in public Actions logs), and GitHub-hosted minutes
(~4,500 wall-min/month of runs vs a 2,000-minute private quota) won't last. CI moves to
`alltrue.daan.lifenet.com.tw` (`daan-ai`, 4 vCPU, 3.4 GB RAM, 189 GB free), a host that ALSO runs the
staging app (`/home/admin/alltrue-stage`) and its MySQL on `127.0.0.1:3306`. Isolation must therefore be
proven, not assumed.

## Isolation design (each layer verified separately by `02-verify-isolation.sh`)

| Layer | What | Why a single layer is not enough |
|---|---|---|
| Identity | user `ghrunner`, no sudo, **not** in `docker` (root-equivalent) / `admin` | the `docker` group alone would allow `-v /:/host` |
| Rootless Docker | ghrunner's own daemon; containers map to sub-UIDs 300000+ | a container can only read what ghrunner can read |
| Files | `/home/*` 750 (unchanged values; recorded). Staging `.env` is `root:root 644` and is NOT changed (could break staging); `/home/admin` 750 keeps others out and step 2 proves ghrunner cannot read it | file mode alone is one layer; containers/network/MySQL checks cover the rest |
| Network | nftables: ghrunner-owned TCP to `:3306`/`:33060` → reset, all addresses | rootless container egress is ghrunner-owned, so containers are covered too |
| MySQL | ghrunner has no MySQL account; socket and TCP logins must fail | the socket is world-writable by design (`srwxrwxrwx`) |
| Root hygiene | tarball checksum-pinned; root never runs/writes ghrunner-writable files; hooks root-owned | otherwise a job could plant code that root later executes |

CI's own test DB runs in a container published on `127.0.0.1:33306` (positive control in step 2), never 3306.

### Cross-job persistence (what a malicious job could leave behind for the next job)

| Path | Status |
|---|---|
| Runner code (`bin/`, `runsvc.sh`, `config.sh`), `.env`, hooks | **Blocked**: root-owned; `--disableupdate`; step 2 proves ghrunner cannot write them |
| Firewall guard removed/flushed | **Fail-closed**: the unit's `ExecStartPre` (root) requires the table; every job's start hook refuses if `127.0.0.1:3306`/`::1:3306` is reachable |
| ghrunner's home (`~/.bashrc`, `~/.config/systemd/user`, `~/.docker`, rootless Docker images) | **Residual risk**: a job could plant files that later jobs (same user) pick up. The full fix is ephemeral runners (fresh user/home per job, JIT-registered), which needs a fine-grained PAT stored root-only on the host. This is a Founder decision; see the PR. |

## Cleanup scope

`job-started.sh` snapshots the containers, networks and volumes in **ghrunner's rootless daemon**. `job-completed.sh`
removes only items that were not in that snapshot. It also empties only that job's workspace under
`/opt/actions-runner/_work/`. It cannot see the system Docker daemon or staging. Step 2 proves this with a
pre-existing container that must survive, a job container and network that must go, and the system container list unchanged.

## Steps (run on daan-ai)

```bash
sudo bash 01-install-isolation.sh          # prepare only; runner NOT registered or running
sudo bash 02-verify-isolation.sh           # must print: ALL CHECKS PASSED
sudo bash 03-enable-runner.sh <REG_TOKEN>  # re-runs step 2 first; refuses on any FAIL
```

After step 3, workflows still use GitHub-hosted runners until the repo variable `CI_RUNNER` is set
(separate PR: workflows read `runs-on: ${{ vars.CI_RUNNER || 'ubuntu-latest' }}` and the PHPUnit DB moves
to port 33306).

## Impact on daan-ai

- New user `ghrunner`, `/opt/actions-runner`, `/usr/local/lib/daan-runner`, and the units
  `daan-runner-guard.service` (firewall) and `daan-ci-runner.service` (step 3).
- Packages: `uidmap`, `passt`, `dbus-user-session`.
- An AppArmor profile for `rootlesskit`, needed because Ubuntu restricts unprivileged user namespaces.
- File modes:
  - `/home/admin`, `/home/jerry`, `/home/jeng` are set to 750. They already were 750; the old modes are recorded.
  - The staging `.env` is not touched.
- Load: one job at a time. PHPUnit plus a MySQL container needs about 1.5 GB of the 3.4 GB, which competes with staging during a CI run.

## Rollback

```bash
# repo: unset variable CI_RUNNER first (workflows return to GitHub-hosted)
sudo bash 99-rollback.sh [REMOVAL_TOKEN]
```

Rollback stops and removes the runner and its unit. It also removes ghrunner's rootless Docker, the user, the subuid
entries, the firewall table, the AppArmor profile and the hooks, and it restores the recorded home-dir modes.
The installed packages stay.
