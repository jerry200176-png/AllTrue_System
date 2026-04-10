# Incident Record - 2026-04-10

## Summary
- Trigger: Git/GitHub synchronization operations were performed while the working tree had large mixed changes and mismatched filesystem ownership.
- Impact:
  - Site appeared to "rollback" to an old page/version.
  - API returned `500` due to environment/cache/DB credential mismatch.
  - Branch switching failed with permission errors in `frontend/dist` and `frontend/node_modules`.

## Root Causes
1. **Cross-history branch checkout on a dirty/complex tree**
   - `main` and `origin/main` had unrelated histories.
   - Checkout attempted to rewrite many tracked files at once.
2. **Filesystem ownership mismatch**
   - Some frontend folders were owned by another user (`jeng`), causing `Permission denied`.
3. **Runtime config drift**
   - Laravel cache files contained stale paths/config.
   - `.env` DB credentials did not match actual local DB user/password.
4. **Fallback old site exposure**
   - Apache default vhost (`/var/www/html`) still served an old page when target app path was unavailable/incorrect.

## What Was Fixed
- Repository was hard-reset to a known good commit (`3312074`).
- Restored critical runtime files:
  - `backend/public/.htaccess`
  - `backend/server.php`
- Corrected DB credentials in `backend/.env` to working values for this machine:
  - `DB_DATABASE=AllTrue`
  - `DB_USERNAME=admin`
  - `DB_PASSWORD=admin123`
- Removed stale Laravel bootstrap cache files so new env takes effect.
- Verified:
  - Website root responds `200`.
  - `api/v1/branches` responds `200`.

## Prevention SOP (Must Follow Next Time)
1. **Before any GitHub/branch surgery**
   - `git status -sb` must be clean or intentionally committed.
   - `git remote -v` verified.
   - `git fetch --all` done before push/rebase/cherry-pick.
2. **If remote has unrelated history**
   - Do NOT force checkout on current tree.
   - Use a new branch from remote base:
     - `git checkout -b pr-fix origin/main`
     - `git cherry-pick <commit>`
3. **Never run mixed ownership operations**
   - Do not use `sudo` for npm/build in app folders.
   - Ensure runtime writable dirs are owned by service user/group.
4. **After env changes**
   - Clear Laravel cache artifacts:
     - `bootstrap/cache/config.php`
     - `bootstrap/cache/services.php`
     - `bootstrap/cache/packages.php`
5. **Pre-release smoke check**
   - `/` returns 200
   - `/api/v1/branches` returns 200
   - frontend assets referenced by `backend/public/index.html` exist

## Quick Triage Commands
```bash
# 1) Git state
git status -sb
git log --oneline -5

# 2) App/API health
curl -I http://127.0.0.1/
curl -i http://127.0.0.1/api/v1/branches

# 3) Laravel cache reset (if env/path drift suspected)
rm -f backend/bootstrap/cache/config.php backend/bootstrap/cache/services.php backend/bootstrap/cache/packages.php
```

