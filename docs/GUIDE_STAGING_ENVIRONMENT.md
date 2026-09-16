# Staging environment (issue #868)

## Status (read this first)

| Layer | Current state |
|---|---|
| Repo docs + `scripts/infra/setup-staging-env.sh` | Instructions only — **do not treat as proof that a staging host exists** |
| Dedicated staging host / `AllTrue_staging` DB / `STAGING_*` secrets | Owner-provisioned; may not exist yet |
| Automatic staging deploy workflow | **Not present** (blocked on control-plane carve-out; see below) |
| Prod deploy gated on staging | **Not done** — Founder decision required |

This guide is repo documentation. Running or merging it does not create
infrastructure, secrets, or a live staging URL.

## What this is

A second copy of the app on a **dedicated staging host** — so `main` gets
tested against something before real users see it.

Deliberately **not** the production Pi: `CONTROL_PLANE_CONTRACT.md` I1 says
only `deploy.yml` (or POP Executor) may execute changes on the production
box. An earlier attempt at a second SSH-based deploy path on the same Pi was
archived (`docs/archive/control-plane-shadow-v1/`) precisely because two
execution paths onto one box caused confusion. Reopening that would need a
formal `[contract-change]` PR against I1–I5. A separate host sidesteps the
boundary entirely instead of reopening it.

| | Production | Staging (target) |
|---|---|---|
| Host | Pi (`/home/admin`) | dedicated staging host (`/home/staging/AllTrue_System`) |
| OS / runtime | Debian 12 · Apache 2.4 · PHP 8.2-FPM · MariaDB 10.11 · Node 22 (build) · Composer 2 | **same stack** (parity) |
| SSH key | `PI_SSH_KEY` | `STAGING_SSH_KEY` (separate key, separate host) |
| DB | `AllTrue` | `AllTrue_staging` (staging-only credentials) |
| Deploy | `deploy.yml` on CI-green `main` | **manual** until a `[contract-change]` allows a non-prod workflow |
| Auto-rollback | Yes | No — leave a broken staging up for inspection |

## Lifecycle (keep these distinct)

| Stage | Meaning | Who / how |
|---|---|---|
| **1. Provisioning** | OS packages, Apache vhost, MariaDB schema/user, empty checkout, staging `.env` | Human on the staging host — once |
| **2. Deployment** | Checkout a known `main` SHA, `composer install`, migrate, frontend build | Human SSH for now (no auto workflow) |
| **3. Smoke** | Unauthenticated health / deployment identity checks | Human curl; no production `SMOKE_*` credentials |
| **4. Promotion** | Gate production deploy on staging health | **Not implemented** — Founder `[contract-change]` required |

## 1. Provisioning (one-time, manual)

Target host: **Debian 12 (bookworm)** dedicated VPS or second machine — never
the production Pi.

### 1a. Install runtime packages (match production)

Debian 12 default repos already provide PHP 8.2, Apache 2.4, and MariaDB 10.11.
Node 22 is build-only (frontend); install via NodeSource or nvm. Composer 2
via the official installer if the distro package is missing or too old.

```bash
sudo apt-get update
sudo apt-get install -y \
  apache2 \
  php8.2 php8.2-cli php8.2-fpm \
  php8.2-mysql php8.2-mbstring php8.2-xml php8.2-curl \
  php8.2-zip php8.2-bcmath php8.2-gd php8.2-intl \
  mariadb-server \
  unzip git curl

# Composer 2 (if needed)
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Node 22 (example: NodeSource; nvm is also fine)
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt-get install -y nodejs

sudo a2enmod rewrite proxy_fcgi setenvif
sudo a2enconf php8.2-fpm
sudo systemctl enable --now apache2 php8.2-fpm mariadb
```

Verify before continuing:

```bash
php -v          # 8.2.x
apache2 -v      # 2.4.x
mysql --version # MariaDB 10.11.x (client still named mysql)
node -v         # v22.x
composer -V     # 2.x
```

No production Pi secrets, `PI_*` keys, or production DB credentials are
required for this step.

### 1b. Run the provisioning script

```bash
# On the staging host, from a throwaway clone or after first clone:
bash scripts/infra/setup-staging-env.sh <git-remote-url>
```

The script:

- clones (or reuses) `/home/staging/AllTrue_System`
- creates MariaDB database `AllTrue_staging` + least-privilege user `atr_staging`
- writes an Apache vhost with DocumentRoot → `backend/public` and PHP 8.2-FPM
- prints next steps for **staging-only** SSH key + GitHub `staging` environment secrets

It does **not** deploy application code beyond the clone tip, does **not**
prove a public staging URL exists, and does **not** touch production.

### 1c. Staging `.env` (hand-written)

Copy `backend/.env.example` → `/home/staging/AllTrue_System/backend/.env` and set
at least:

```
APP_ENV=staging
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=AllTrue_staging
DB_USERNAME=atr_staging
DB_PASSWORD=<value printed / retained from the script>
```

Use staging-only credentials only. Never copy production `.env` or `PI_*`
secrets onto this host.

Secrets storage when ready: GitHub Environment `staging` (see
`docs/runbooks/GITHUB_ENVIRONMENTS_SETUP.md`) — not repo-level production
secrets.

## 2. Deployment (manual until contract carve-out)

**The auto-deploy workflow is not included.** `scripts/control-plane-lint.mjs`
(I1 enforcement) flags any tracked `.github/workflows/*.yml` that combines an
SSH deploy step with `git fetch origin main` / `git reset --hard origin/main`
as a shadow production-deploy path — it cannot tell "this targets a separate
staging host" from static analysis, and that is by design (see
`docs/archive/control-plane-shadow-v1/`). Until a formal `[contract-change]` PR
amends I1–I5 to carve out a non-production exception, deploy to staging by
hand:

```bash
ssh <staging-user>@<staging-host>
cd /home/staging/AllTrue_System
git fetch origin
git reset --hard origin/main   # or a specific CI-green SHA
cd backend && composer install --no-interaction --prefer-dist
php artisan migrate --force
cd ../frontend && npm ci && npm run build
sudo systemctl reload php8.2-fpm
```

Do **not** change `.github/workflows/deploy.yml` for staging.

## 3. Smoke (staging-only)

After a manual deploy, check unauthenticated endpoints on the staging host
(adjust host/URL):

```bash
curl -fsS http://127.0.0.1/api/v1/health
curl -fsS http://127.0.0.1/deployment.json
```

Do **not** reuse production `SMOKE_*` credentials or point production
UptimeRobot at staging. Authenticated smoke remains out of scope until
staging-only fixtures exist.

## 4. Promotion (Founder decision — not in this doc’s authority)

Wiring `deploy.yml` to require staging health, or adding
`staging-deploy.yml`, would touch I1’s execution-authority boundary and needs
its own `[contract-change]` review. Do that only after staging has been
provisioned and manually exercised — not in the same change that aligns
docs/scripts with runtime parity.

## Deliberately out of scope (first pass)

- Prod deploy gated on staging passing
- Authenticated smoke / Playwright against staging
- Separate Sentry DSN (shared DSN is acceptable until noise forces a split)
- Automatic `staging-deploy.yml` without a control-plane carve-out
