# Staging environment (issue #868)

## Status (read this first)

| Layer | Current state |
|---|---|
| Repo docs + `scripts/infra/setup-staging-env.sh` | Instructions only — **do not treat as proof that a staging host exists** |
| Dedicated Dell staging host / `AllTrue_staging` DB | Owner-provisioned; may not exist yet |
| GitHub Environment `staging` / `STAGING_*` secrets | **Optional (Stage D)** — not required for Stages A–C |
| Automatic staging deploy workflow | **Not present** (blocked on control-plane carve-out) |
| Prod deploy gated on staging | **Not done** — Founder decision (Stage E) |

This guide is repo documentation. Running or merging it does not create
infrastructure, secrets, DNS, or a live staging URL. Canonical PR for this
provisioning path: **#2967** (PR #2968 is superseded / reference only).

## What this is

A second copy of the app on a **dedicated staging host** so `main` can be
exercised before real users see it.

**Dell staging path (Founder direction):** native **production-parity** stack,
not Ubuntu-for-staging and not container-only staging. The Dell may later
become a Production Candidate, so match the current Pi production runtime.

Deliberately **not** the production Pi: `CONTROL_PLANE_CONTRACT.md` I1 says
only `deploy.yml` (or POP Executor) may execute changes on the production
box. An earlier second SSH deploy path on the same Pi was archived
(`docs/archive/control-plane-shadow-v1/`). A separate host sidesteps that
boundary instead of reopening it.

| | Production (Pi) | Staging (Dell target) |
|---|---|---|
| Host | Pi (`/home/admin`) | dedicated Dell (`/home/staging/AllTrue_System`) |
| OS | Debian 12 | **Debian 12 amd64 minimal** |
| Runtime | Apache 2.4 · PHP 8.2-FPM (`proxy_fcgi`) · MariaDB 10.11 · Node 22 (build) · Composer 2 | **same** |
| SSH | `PI_SSH_KEY` (production only) | operator key for A–C; optional CI deploy pubkey later (Stage D) |
| DB | `AllTrue` | `AllTrue_staging` + staging-only `atr_staging` |
| Deploy | `deploy.yml` | **manual exact-SHA** until Stage E carve-out |
| Auto-rollback | Yes | No — leave broken staging up for inspection |

**Out of scope for this guide’s bring-up:** `deploy.yml` edits, production
secrets, production DB, DNS cutover, Dell→production migration.

## Lifecycle stages (keep separate)

| Stage | Meaning | Required for usable staging? |
|---|---|---|
| **A — Host provisioning** | OS packages, Apache vhost, MariaDB DB/user, checkout dir, staging `.env` | Yes |
| **B — Manual exact-SHA deployment** | `git reset --hard <sha>`, composer, migrate, frontend build | Yes |
| **C — Staging smoke / TrueFit acceptance** | Health + identity (+ TrueFit checks when enabled) | Yes (acceptance) |
| **D — Optional GitHub staging automation** | Environment `staging`, `STAGING_*` secrets, deploy pubkey only on host | **No** — do not block A–C |
| **E — Optional future production gate** | Gate `deploy.yml` / promotion on staging | **No** — Founder `[contract-change]` |

`setup-staging-env.sh` implements **Stage A helpers only**.

---

## Stage A — Host provisioning

Target: **Debian 12 (bookworm) amd64 minimal** on the Dell (or equivalent
dedicated machine). Never the production Pi.

### A1. Install runtime packages (match production)

Debian 12 default repos provide PHP 8.2, Apache 2.4, and MariaDB 10.11.
Node 22 is build-only; Composer 2 via official installer if needed.

```bash
sudo apt-get update
sudo apt-get install -y \
  apache2 \
  php8.2 php8.2-cli php8.2-fpm \
  php8.2-mysql php8.2-mbstring php8.2-xml php8.2-curl \
  php8.2-zip php8.2-bcmath php8.2-gd php8.2-intl \
  mariadb-server \
  unzip git curl

curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt-get install -y nodejs

sudo a2enmod rewrite proxy_fcgi setenvif
sudo a2enconf php8.2-fpm
sudo systemctl enable --now apache2 php8.2-fpm mariadb
```

Verify:

```bash
php -v          # 8.2.x
apache2 -v      # 2.4.x
mysql --version # MariaDB 10.11.x
node -v         # v22.x
composer -V     # 2.x
```

No production Pi secrets, `PI_*` keys, or production DB credentials are
required.

### A2. Run the provisioning script

```bash
# Optional: supply a password you will keep (recommended for reruns)
STAGING_DB_PASSWORD='…' bash scripts/infra/setup-staging-env.sh <git-remote-url>

# Or omit STAGING_DB_PASSWORD on first run — script generates one, verifies
# login, then prints the verified password once.
bash scripts/infra/setup-staging-env.sh <git-remote-url>
```

**First-run semantics** (`atr_staging` absent):

1. Create `AllTrue_staging`.
2. Create `atr_staging`@`localhost` and @`127.0.0.1` with the chosen password
   (env, or generated).
3. Grant DB privileges; flush.
4. **Verify** `mysql --protocol=TCP -h 127.0.0.1 -u atr_staging … AllTrue_staging`.
5. Only after verification succeeds may a generated password be printed.

**Rerun semantics** (`atr_staging` already present):

1. Require `STAGING_DB_PASSWORD` in the environment.
2. Prove that password authenticates before doing anything else with it.
3. If it does not authenticate → **fail closed** (no `CREATE USER IF NOT EXISTS`
   with a fresh random password; no silent `ALTER USER`).
4. Ensure database + grants; verify login again.
5. Do not reprint a known env password.

The script also writes the Apache vhost (`proxy_fcgi` →
`unix:/run/php/php8.2-fpm.sock`) and clones `/home/staging/AllTrue_System` if
needed. It does **not** deploy an exact SHA, does **not** require GitHub
secrets, and does **not** touch production.

### A3. Staging `.env` (hand-written)

```text
APP_ENV=staging
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=AllTrue_staging
DB_USERNAME=atr_staging
DB_PASSWORD=<the password that just verified>
```

Never copy production `.env` or `PI_*` secrets onto this host.

### A4. Operator SSH for Stages A–C (manual)

Use your normal operator SSH account/key to administer the Dell. **Do not**
generate a deploy private key on the staging host.

GitHub `STAGING_*` secrets are **not** required to finish Stages A–C.

---

## Stage B — Manual exact-SHA deployment

Until a formal `[contract-change]` carves out a non-production workflow under
I1–I5, deploy by hand to a **CI-green SHA** (prefer that over floating
`origin/main`):

```bash
ssh <staging-user>@<dell-staging-host>
cd /home/staging/AllTrue_System
git fetch origin
git reset --hard <CI-green-SHA>
cd backend && composer install --no-interaction --prefer-dist
php artisan migrate --force
cd ../frontend && npm ci && npm run build
sudo systemctl reload php8.2-fpm
```

Do **not** change `.github/workflows/deploy.yml`.

`scripts/control-plane-lint.mjs` deliberately treats any extra workflow that
SSH-deploys + `git reset --hard origin/main` as a shadow production path
(see `docs/archive/control-plane-shadow-v1/`).

---

## Stage C — Staging smoke / TrueFit acceptance

Unauthenticated checks (adjust host/URL; no production `SMOKE_*`):

```bash
curl -fsS http://127.0.0.1/api/v1/health
curl -fsS http://127.0.0.1/deployment.json
```

When TrueFit flags are enabled on staging, add the acceptance checks from
`docs/truefit/` (hash route / shell) on this host only. Do not point
production UptimeRobot or production smoke credentials at staging.

---

## Stage D — Optional GitHub staging environment automation

**Not required** for A–C. When Founder wants CI-ready secrets later:

1. Generate the ed25519 **keypair on a trusted operator or CI machine**.
2. Private key stays there / becomes GitHub Environment `staging` secret
   `STAGING_SSH_KEY` — **never** created on the Dell and copied outward.
3. Install **only the public key** on the staging account:

```bash
install -d -m 700 ~/.ssh
# append the single .pub line to ~/.ssh/authorized_keys
chmod 700 ~/.ssh
chmod 600 ~/.ssh/authorized_keys
```

4. Set other `STAGING_*` values in Environment `staging` per
   `docs/runbooks/GITHUB_ENVIRONMENTS_SETUP.md`.

There is still **no** tracked `staging-deploy.yml` until Stage E’s contract
work lands.

---

## Stage E — Optional future production gate

Wiring `deploy.yml` to require staging health, or adding
`staging-deploy.yml`, needs its own `[contract-change]` against I1–I5.
Do that only after A–C have been exercised on the Dell — not in the same
change that stands staging docs/scripts up.

---

## Deliberately out of scope (this provisioning path)

- Editing `deploy.yml` / production runtime / production DB
- Production secrets, DNS cutover, Dell production migration
- Requiring Stage D before A–C
- Authenticated production smoke credentials on staging
- Automatic staging deploy without a control-plane carve-out
