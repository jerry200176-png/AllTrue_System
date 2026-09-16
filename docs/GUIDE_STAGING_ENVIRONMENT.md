# Staging environment (issue #868)

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

| | Production | Staging |
|---|---|---|
| Host | Pi (`/home/admin`, DocumentRoot `/home/admin/backend/public`) | dedicated Dell/LAN host (`/home/staging/AllTrue_System`) |
| OS target | Debian 12 (bookworm) aarch64 | Debian 12 amd64 (production-like; do not invent Ubuntu/nginx/MySQL) |
| Web | Apache 2.4 → `proxy_fcgi` → PHP 8.2-FPM | same pattern (no mod_php, no nginx) |
| DB | MariaDB 10.11 on `127.0.0.1` only, DB `AllTrue` | MariaDB 10.11 on `127.0.0.1` only, DB `AllTrue_staging` |
| SSH key | `PI_SSH_KEY` | `STAGING_SSH_KEY` (separate key, separate host) |
| Deploy trigger | CI green on `main` | CI green on `main` (same commit, manual deploy until contract carve-out) |
| Auto-rollback | Yes | No — leave broken staging for inspection |

## Production parity snapshot (read-only discovery, 2026-09-16)

Use this as the staging install target. Do **not** copy production secrets,
`.env`, or DB credentials.

| Item | Production (Pi) | Staging target |
|---|---|---|
| Debian | 12 (bookworm) | 12 (bookworm) amd64 |
| Apache | 2.4.66, `mpm_event`, **no** `mod_php` | 2.4.x, same modules |
| PHP handler | `SetHandler proxy:unix:/run/php/php8.2-fpm.sock\|fcgi://localhost/` | identical |
| PHP | 8.2.29 CLI + FPM | 8.2.x CLI + FPM |
| PHP extensions | bz2, curl, gd, mbstring, mysqli, pdo_mysql, xml, zip, opcache, … | install required set only |
| PHP-FPM listen | `/run/php/php8.2-fpm.sock` (www-data) | same |
| MariaDB | 10.11.6, `bind_address=127.0.0.1` | 10.11.x, localhost only |
| `character_set_server` | `utf8mb4` | `utf8mb4` |
| `collation_server` | `utf8mb4_general_ci` | `utf8mb4_general_ci` |
| `sql_mode` | `ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` | match |
| Node | v22.22.0 | Node 22 |
| Composer | 2.9.5 | Composer 2 |

Laravel front-controller rewrite lives in `backend/public/.htaccess`
(`AllowOverride All` on DocumentRoot). Staging vhost must enable
`rewrite`, `headers`, `proxy`, `proxy_fcgi`, `ssl` (if TLS), and **not**
install `libapache2-mod-php*`.

## One-time setup (do this once, manually)

1. Provision **Debian 12 amd64** on the dedicated staging host (LAN example:
   `daan-staging` / `192.168.0.202`). Install packages to match the matrix
   above: Apache 2.4, PHP 8.2-FPM + required extensions, MariaDB 10.11,
   Composer 2, Node 22, git/curl/unzip.
2. Create Linux user `staging` with sudo for service reloads. Install an
   operator SSH pubkey before any agent work.
3. SSH in and run
   `bash scripts/infra/setup-staging-env.sh <repo-url>` —
   creates `/home/staging/AllTrue_System`, MariaDB `AllTrue_staging` +
   localhost-only user, and an Apache vhost (HTTP LAN-only by default).
4. Follow the script's printed next steps: generate a dedicated
   `STAGING_SSH_KEY` (never reuse `PI_SSH_KEY`), add the five
   `STAGING_*` secrets to the repo **only when automation is approved**,
   and hand-write `/home/staging/AllTrue_System/backend/.env`.

### Staging `.env` isolation (mandatory)

- `APP_ENV=staging`
- `APP_DEBUG=false` unless a documented staging-only debug window exists
- `DB_DATABASE=AllTrue_staging`
- staging-only `DB_USERNAME` / `DB_PASSWORD` (never production values)
- `DB_HOST=127.0.0.1`
- Leave LINE / mail / payment / SMS / Telegram webhook secrets **empty**
  or point at sandbox-only credentials you control
- Leave `SENTRY_LARAVEL_DSN` empty on first bring-up (or use a
  staging-only DSN); do **not** reuse production DSN by default
- `TRUEFIT_V1=true` / frontend `VITE_TRUEFIT_V1=true` only while running
  TrueFit staging acceptance; roll OFF afterward

### External integration safety classes

Classify each integration before enabling:

| Class | Meaning |
|---|---|
| DISABLED | Credentials empty / feature flag off; no outbound calls |
| SANDBOX | Vendor sandbox / test channel you own |
| SAFE_READ_ONLY | Outbound allowed only if it cannot notify real customers |
| BLOCKED | Must not be configured on staging |

Minimum inventory: LINE, email, payment, SMS, webhooks (LINE/Telegram),
Sentry, other external APIs. Never send a staging event to a real customer.

5. **The auto-deploy workflow is not included.** `scripts/control-plane-lint.mjs`
   (I1 enforcement) flags any tracked `.github/workflows/*.yml` that combines
   an SSH deploy step with `git fetch origin main` / `git reset --hard
   origin/main` as a shadow production-deploy path — it can't tell "this
   targets a separate staging host" from static analysis, and that's by
   design (see `docs/archive/control-plane-shadow-v1/` for why a second
   deploy path was archived before). Until a formal `[contract-change]` PR
   amends I1–I5 to carve out a non-production exception, deploy to staging
   manually (see `docs/runbooks/STAGING_RECOVERY.md`).

## Manual deploy of an exact `main` SHA

After CI PHPUnit + Security Scan are green on the chosen commit:

```bash
cd /home/staging/AllTrue_System
git fetch origin
git checkout -f <40-char-sha>
cd backend && composer install --no-interaction --prefer-dist --no-dev
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
cd ../frontend && npm ci && npm run build
sudo systemctl reload php8.2-fpm apache2
curl -fsS http://127.0.0.1/api/v1/health
# Prove runtime identity matches the exact SHA (version.json / deployment.json).
```

Do not call deploy successful unless runtime identity proves the SHA.

## Not done yet (deliberately out of scope for the first pass)

- **Prod deploy is not gated on staging passing.** They run independently
  off the same CI-green signal. Wiring `deploy.yml` to require staging
  health first would itself touch I1's execution-authority boundary and
  needs its own `[contract-change]` review — do that once staging has run
  clean for a couple of weeks, not in the same PR that stands staging up.
- **No production backup replication to Dell.** Staging-local dump/restore
  only (`docs/runbooks/STAGING_RECOVERY.md`).
- **No production DNS/NAT for staging.** LAN IP / hosts-file is enough for
  Slice 0 acceptance.
- **TrueFit subdomain** is out of scope; use existing-origin `/#/truefit`.
