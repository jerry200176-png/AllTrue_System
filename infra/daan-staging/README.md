# Daan staging V1 (isolated AllTrue staging)

Lifecycle states for this environment (never call these "production verified"):

`CODE_WRITTEN` → `TESTED` → `STAGING_DEPLOYED` → `STAGING_RUNTIME_VERIFIED`

## Topology

```
Daan host (alltrue.daan.lifenet.com.tw)
├── Dify / Hermes / host Apache / host MySQL :3306 — unchanged
└── Docker project `alltrue-stage`
    ├── alltrue-stage-app   (PHP-FPM, mem≤512m)
    ├── alltrue-stage-nginx → 127.0.0.1:18080 only
    └── alltrue-stage-mysql → Docker network only (no host 3306)
```

Access: `ssh -L 18080:127.0.0.1:18080 -i ~/.ssh/alltrue_daan_stage admin@alltrue.daan.lifenet.com.tw`

## Why not root `docker-compose.yml`?

That file publishes host `8080` and `3306`, which collide with Dify and host MySQL on Daan.
This tree is a dedicated composition under `infra/daan-staging/` so it does **not** classify as a Pi application runtime path (`backend/` / `frontend/` / `scripts/`).

## PHP/MySQL parity

`infra/daan-staging/Dockerfile` installs `pdo_mysql` (and keeps `pdo_pgsql`).
Root `backend/Dockerfile` still lacks `pdo_mysql` — tracked separately as `INFRA-PDO-MYSQL-MISSING` and **not** fixed here, because editing `backend/Dockerfile` would mark production activation deployable.

## Host bootstrap (Founder / interactive sudo)

`admin` can SSH passwordlessly but is **not** in the `docker` group and has no passwordless sudo.
One-time host bootstrap (manual):

1. Add `admin` to `docker` (or document an approved operator group).
2. Confirm `docker ps` works without interactive sudo.
3. Do **not** stop Dify/Hermes, change firewall, or expose public staging ports.

## Commands (from repo root on Daan checkout)

```bash
cp infra/daan-staging/.env.example infra/daan-staging/.env
# generate staging-only secrets; never paste production credentials
openssl rand -hex 24  # use for STAGING_DB_PASSWORD / ROOT / APP_KEY

infra/daan-staging/bin/preflight.sh
infra/daan-staging/bin/up.sh <exact-sha>
infra/daan-staging/bin/migrate.sh
infra/daan-staging/bin/identity.sh
infra/daan-staging/bin/health.sh
infra/daan-staging/bin/smoke.sh
infra/daan-staging/bin/down.sh          # keeps MySQL volume
infra/daan-staging/bin/rollback.sh <prior-sha>
```

## Explicit non-goals

Pi production, production DNS, production DB sync, public TLS/DNS for staging,
TrueFit ON, Temporal, self-hosted runners, Dify/Hermes changes.
