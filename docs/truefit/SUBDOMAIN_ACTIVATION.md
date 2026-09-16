# TrueFit subdomain activation (Slice 0)

**Status:** Documentation only — **do not activate DNS or production routing without Founder approval.**

TrueFit Slice 0 ships as the same Vue SPA + Laravel API as AllTrue. No separate backend, auth stack, or cookies are required.

## Intended URL

```text
https://truefit.<existing-domain>/
```

Example (production): `https://truefit.daan.lifenet.com.tw/`

The frontend detects `truefit.` host prefixes via `frontend/src/lib/truefitHost.js` and renders the standalone TrueFit shell after normal staff login.

**Auth note:** staff Bearer tokens live in **origin-scoped** `localStorage` (`alltrue_session`). The subdomain does **not** share tokens with the primary origin. See [`AUTH_SUBDOMAIN_FINDINGS.md`](AUTH_SUBDOMAIN_FINDINGS.md). **v0.1 pilot should use `/#/truefit` on the existing origin.**

## Minimal Apache change (production Pi)

Assuming the main vhost already serves `backend/public` with SPA fallback:

1. Add a DNS `A`/`CNAME` record: `truefit` → same Pi IP as the primary site.
2. Add a **ServerAlias** (or separate vhost) pointing to the **same** `DocumentRoot`:

```apache
# Example — adjust to match docs/DEPLOYMENT.md / live vhost
<VirtualHost *:443>
  ServerName daan.lifenet.com.tw
  ServerAlias truefit.daan.lifenet.com.tw
  DocumentRoot /var/www/alltrue/backend/public
  # ... existing SSL + rewrite rules unchanged ...
</VirtualHost>
```

3. Rebuild frontend with `VITE_TRUEFIT_V1=true` and deploy via `deploy.yml` as usual.
4. Set `TRUEFIT_V1=true` in production `backend/.env`, then run the standard post-deploy optimize/opcache step from the control-plane runbook.

**No cross-domain cookie changes** are required when the subdomain shares the registrable domain and existing Sanctum/session cookies already cover subdomains. If cookies are host-only today, stop and get Founder approval before changing `SESSION_DOMAIN`.

## Internal routing (available now)

Without DNS changes, teachers can open TrueFit at:

- `https://<existing-domain>/#/truefit`
- `https://<existing-domain>/?truefit=1`

Teacher sidebar shows **TrueFit** only when both `VITE_TRUEFIT_V1=true` (build) and `TRUEFIT_V1=true` (backend) are set.

## Rollback

1. Set `TRUEFIT_V1=false` in production `.env`.
2. Redeploy prior frontend build **or** rebuild with `VITE_TRUEFIT_V1` unset/false.
3. Optional: remove `ServerAlias` / DNS — not required for instant disable.
