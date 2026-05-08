# Smoke Test Runbook

This runbook defines the read-only post-deploy smoke checks used by `deploy.yml`.

## Purpose

- Catch critical route/auth regressions immediately after deployment.
- Keep checks non-destructive (no writes, no migrations, no cache mutations).
- Align with "big company" rollout gates: health -> contract smoke -> role read-path.

## Script

- Path: `scripts/smoke-api.sh`
- Default mode: public read-only checks
- Optional mode: teacher authenticated read-path checks (when secrets are provided)

## Checks Performed

Without credentials:

- `GET /api/v1/health`
- `GET /api/v1/branches`
- `POST /api/v1/auth/login` with empty payload (route liveness, non-5xx)

With teacher credentials (`SMOKE_TEACHER_LOGIN`, `SMOKE_TEACHER_PASSWORD`):

- Login token retrieval
- `GET /api/v1/me`
- `GET /api/v1/class-sessions?...`
- `GET /api/v1/learning-records?...`

All checks are non-5xx contract checks; any failed check exits non-zero.

## CI/Deploy Integration

`deploy.yml` exports the following optional secrets before running the script:

- `SMOKE_TEACHER_LOGIN`
- `SMOKE_TEACHER_PASSWORD`
- `SMOKE_BRANCH_ID` (optional, defaults handled by script)

When credentials are absent, authenticated checks are skipped and public checks still run.

## Local Dry Run (WSL2)

```bash
bash scripts/smoke-api.sh
```

With optional credentials:

```bash
SMOKE_BASE_URL="https://daan.lifenet.com.tw" \
SMOKE_TEACHER_LOGIN="teacher@example.com" \
SMOKE_TEACHER_PASSWORD="***" \
SMOKE_BRANCH_ID="15" \
bash scripts/smoke-api.sh
```

## Failure Handling

- Smoke failure in `deploy.yml` triggers existing rollback branch.
- After rollback, deployment exits failed for operator follow-up.

## Security Notes

- Never print passwords.
- Use repo/org secrets for credentials, not plaintext in workflow files.
- Keep smoke account scoped to minimum read permissions.
