# AGENTS.md

If you are an AI agent working in this repo, read this file first.

## Mission

Keep the AllTrue system stable while delivering small, verifiable changes.

## First-read order

1. `README.md`
2. `AI_QUICKSTART.md`
3. `docs/OPERATIONS_RUNBOOK.md`
4. `docs/GITHUB_SYNC_WORKFLOW.md`

## Non-negotiable rules

- Collaboration branch is `jerry-sync-main`
- Do not merge backup branches into main collaboration flow
- Avoid destructive git operations on a dirty worktree
- If frontend code changed, run `cd frontend && npm run deploy`
- Validate API health after deploy/config edits

## Project context (short)

- Frontend: Vue 3 in `frontend/`
- Backend: Laravel 8 in `backend/`
- Auth: token in `localStorage.alltrue_session`
- Domain: students, classes, schedules, attendance, billing, learning records

## Common pitfalls

- Wrong PR target branch
- Stale Laravel cache files under `backend/bootstrap/cache/`
- Broken `.env` DB credentials
- Missing routing files (`backend/server.php`, `backend/public/.htaccess`)
- Permission mismatch in frontend build folders

## Working style

- Make minimal scoped edits
- Explain why changes are needed
- Keep docs updated when process changes
- Prefer reversible operations

