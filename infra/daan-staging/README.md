# Daan staging V1: operations

Policy and decisions: [`docs/ops/DAAN_STAGING_V1.md`](../../docs/ops/DAAN_STAGING_V1.md) (single source; this file is the how-to).

## Topology

```
Daan host (alltrue.daan.lifenet.com.tw) -- Dify / Hermes / Apache / host MySQL :3306 untouched
└── rootless Docker (user `admin`), compose project `alltrue-stage`
    ├── alltrue-stage-nginx -> 127.0.0.1:${STAGING_HTTP_PORT:-18080}  (SPA at /, API at /api -> app)
    ├── alltrue-stage-app   (PHP 8.2-FPM, image-baked code -> named volume)
    └── alltrue-stage-mysql (Docker network only, no host port)
```

Root `docker-compose.yml` is not used: it publishes host 8080/3306, which collide with Dify and host MySQL.

## Host checkout immutability (STAGING_HOST_CHECKOUT_MUTATION)

Exact-SHA code is baked into the image (`/opt/alltrue-src`) and synced into the named volume `alltrue-stage-app-code`.
Never bind-mount the host Git `backend/` tree. `assert-host-checkout-clean.sh` must pass after every lifecycle step.
The host checkout is `~/alltrue-stage/AllTrue_System`, always on a detached exact SHA.

## Rootless Docker (one-time, no sudo)

Prereqs (Founder, done): `uidmap`, `slirp4netns`, `loginctl enable-linger admin`.

```bash
dockerd-rootless-setuptool.sh install
systemctl --user enable --now docker
# lifecycle scripts auto-use DOCKER_HOST=unix:///run/user/$(id -u)/docker.sock
DOCKER_HOST=unix:///run/user/$(id -u)/docker.sock docker info | grep -i rootless
```

`preflight.sh` refuses a non-rootless daemon. Do not add `admin` to the docker group or grant passwordless sudo.
A legacy rootful `alltrue-stage` stack holding the port is the only thing that needs sudo to remove
(`sudo docker compose -p alltrue-stage down`); until then set `STAGING_HTTP_PORT` in `.env`.

## Manual lifecycle

```bash
cp infra/daan-staging/.env.example infra/daan-staging/.env   # then replace CHANGE_ME_* with `openssl rand -hex 24`
L=infra/daan-staging/lifecycle
$L/preflight.sh; $L/up.sh <sha>; $L/migrate.sh; $L/seed-demo.sh; $L/smoke.sh; $L/health.sh
$L/down.sh            # keeps MySQL volume
$L/rollback.sh <prior-sha>
$L/validate-cycle.sh  # full cycle rehearsal; evidence in infra/daan-staging/runtime/evidence/
```

## Demo data and logins

`seed-demo.sh` (idempotent) creates one fake campus, a director, a teacher and three students.
Passwords are generated on first run and written **only** to `~/alltrue-stage/DEMO_LOGINS.txt` (host, mode 0600).
Director/teacher log in with `demo-director` / `demo-teacher` plus the generated password.
Parents log in with a seeded StudentID plus phone (no password); the file lists them. Nothing here is committed.

## Auto-update (pull, no inbound access, no GitHub secrets)

`lifecycle/auto-update.sh` runs from a `systemd --user` timer every 15 minutes (`lifecycle/install-timer.sh`):

1. One unauthenticated GitHub API call: newest successful `ci.yml` push run on `main` (4 calls/h, limit 60/h).
2. Same as `~/alltrue-stage/state/last_good_sha`? Do nothing.
3. Otherwise `redeploy.sh <sha>` (build, up, migrate, identity, health), then `smoke.sh` and the clean-checkout assert.
4. Success records the new last-good SHA; failure redeploys the last good SHA.
5. Everything is appended to `infra/daan-staging/runtime/evidence/auto-update.log`.

Inspect: `systemctl --user list-timers alltrue-staging-update.timer`, `journalctl --user -u alltrue-staging-update`.
Pause: `systemctl --user stop alltrue-staging-update.timer`.

## Public URL (Cloudflare Tunnel + Access)

Outbound-only; the host opens no port. `lifecycle/install-cloudflared.sh` installs a pinned, checksum-verified
`cloudflared` into `~/bin`. Then, once:

```bash
~/bin/cloudflared tunnel login                      # Founder opens the printed URL and picks the zone
~/bin/cloudflared tunnel create alltrue-staging
~/bin/cloudflared tunnel route dns alltrue-staging <staging-hostname>
# ~/.cloudflared/config.yml: ingress: [{hostname: <staging-hostname>, service: http://127.0.0.1:18080}, {service: http_status:404}]
~/bin/cloudflared tunnel run alltrue-staging        # run as a user service
```

Then in the Cloudflare dashboard add an Access application on that hostname with one Allow policy: the Founder's email.
Never expose the hostname without that policy. `cert.pem` and the tunnel credentials stay on the host.

## Non-goals

Pi production, production DNS, production DB sync, TrueFit ON, Temporal, self-hosted runners, Dify/Hermes changes.
