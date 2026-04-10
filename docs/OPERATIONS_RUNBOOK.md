# Operations Runbook (SOP + Lessons Learned)

This runbook captures the practical SOP to keep AllTrue stable during development and deployment.

## A. Development SOP

1. Sync first:
   - `git checkout main`
   - `git pull`
2. Implement changes in focused commits.
3. If frontend changed, deploy build:
   - `cd frontend && npm run deploy`
4. Verify:
   - frontend opens
   - key API endpoint responds
5. Sync to GitHub:
   - `./scripts/git-sync.sh "feat/fix: message"`

## B. GitHub SOP

- Main collaboration target is `jerry-sync-main`.
- Feature branches should PR into `jerry-sync-main`.
- Backup branches are **not** for normal merge.
- If PR contains huge unrelated diffs or historical artifacts: close it, do not merge.

## C. Incident lessons (must remember)

From previous incident:
- Mixed/unrelated git histories caused confusion and wrong merge targets.
- Laravel cache/config drift caused app to read outdated paths/config.
- Missing `server.php` or `public/.htaccess` broke API routing/runtime.
- Wrong DB credentials in `backend/.env` caused full API failure.
- Wrong file ownership in frontend build dirs caused deploy/build failures.

## D. Recovery SOP (website looks old / API broken)

1. Confirm current code branch and latest commit:
   - `git status`
   - `git log -1 --oneline`
2. Check app routing essentials:
   - `backend/server.php`
   - `backend/public/.htaccess`
3. Clear Laravel cached files:
   - `backend/bootstrap/cache/*.php` (config/services/packages)
4. Verify DB credentials in `backend/.env`.
5. Rebuild frontend:
   - `cd frontend && npm run deploy`
6. Re-test web + API endpoints.

## E. Pre-merge checklist

- PR target branch is correct (`jerry-sync-main`)
- No accidental artifacts (`dist`, cache, local binaries, temp files)
- Frontend changes have been deployed
- No critical regressions in login, students, scheduling, attendance, finance pages

## F. High-risk areas

- Session deduction / remaining sessions logic
- Subject-unit statistics output format
- Campus/branch data isolation
- Attendance and class-session relationship integrity

## G. Reference docs

- `README.md`
- `AI_QUICKSTART.md`
- `docs/GITHUB_SYNC_WORKFLOW.md`
- `docs/INCIDENT_2026-04-10_GITHUB_AND_SITE_ROLLBACK.md`

