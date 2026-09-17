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

## Host bootstrap (Founder / interactive sudo — session scoped)

`admin` can SSH passwordlessly but is **not** in the `docker` group and has **no** passwordless sudo.

**Do not** add `admin` to the docker group or grant passwordless `sudo docker` in this phase.

Session-scoped Docker (this shell only):

```bash
export PATH="$HOME/alltrue-stage/session-docker-bin:$PATH"
hash -r
# first `docker` call prompts for sudo password once per session
```

Packet on Daan: `~/alltrue-stage/FOUNDER_INTERACTIVE_DOCKER.txt`

## Validation phases

1. **DAAN_STAGING_V1 infra** at merge SHA `142cac7901c5b492a539dc057d47feae2d1d7534`
2. **Product rehearsal** (#3015 / in-app #296) at current `main` after infra ACCEPTED

```bash
# Phase 1 only
infra/daan-staging/lifecycle/validate-cycle.sh

# Phase 1 + #296 rehearsal
PRODUCT_REHEARSAL=1 MAIN_SHA="$(git rev-parse origin/main)" infra/daan-staging/lifecycle/validate-cycle.sh
```

Evidence lands in `infra/daan-staging/runtime/evidence/`. Bring staging **down** after validation unless actively testing.

## Commands (from repo root on Daan checkout)

```bash
cp infra/daan-staging/.env.example infra/daan-staging/.env
# generate staging-only secrets; never paste production credentials
openssl rand -hex 24  # use for STAGING_DB_PASSWORD / ROOT / APP_KEY

infra/daan-staging/lifecycle/preflight.sh
infra/daan-staging/lifecycle/up.sh <exact-sha>
infra/daan-staging/lifecycle/migrate.sh
infra/daan-staging/lifecycle/identity.sh
infra/daan-staging/lifecycle/health.sh
infra/daan-staging/lifecycle/smoke.sh
infra/daan-staging/lifecycle/down.sh          # keeps MySQL volume
infra/daan-staging/lifecycle/rollback.sh <prior-sha>
```

## Explicit non-goals

Pi production, production DNS, production DB sync, public TLS/DNS for staging,
TrueFit ON, Temporal, self-hosted runners, Dify/Hermes changes.
