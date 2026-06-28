# Smoke Test Runbook

> **REFERENCE ONLY — execution reference.** Documents smoke checks invoked by [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml).  
> No incident or deploy authority. **Contract:** [`CONTROL_PLANE_CONTRACT.md`](CONTROL_PLANE_CONTRACT.md) I1.

## Purpose

- Catch critical route/auth regressions immediately after deployment.
- Keep checks non-destructive (no writes, no migrations, no cache mutations).
- Align with "big company" rollout gates: health -> contract smoke -> role read-path.

## Script

- Path: `scripts/smoke-api.sh` — deploy.yml 內嵌 smoke（公開 + 可選 teacher 登入）
- Path: `scripts/post-merge-smoke.sh` — **§B5 post-merge 完整驗收**（公開 + Pi bundle 指紋 + auth API；無密碼時從 Pi DB 讀最新有效 token，唯讀）

## Checks Performed

Without credentials:

- `GET /api/v1/health`
- `GET /api/v1/branches`
- `POST /api/v1/auth/login` with empty payload (route liveness, non-5xx)

`post-merge-smoke.sh` 額外（Layer 2–3）：

- `version.json` hash 與 Pi `git HEAD` 一致
- 部署 bundle 含 `cancelMakeupSchedule` / `trust-summary` 等關鍵字
- Teacher `GET /system/trust-summary` → 200（#529 驗證路徑）
- Director `GET /schedules?type=extra&status=scheduled` → 200
- Director `POST /schedules/{id}/cancel-makeup` probe → 404（auth 正常，非 401）

With teacher credentials (`SMOKE_TEACHER_LOGIN`, `SMOKE_TEACHER_PASSWORD`):

- Login token retrieval
- `GET /api/v1/me`
- `GET /api/v1/class-sessions?...`
- `GET /api/v1/learning-records?...`

All checks are non-5xx contract checks; any failed check exits non-zero.

## CI/Deploy Integration

`deploy.yml` exports the following optional secrets before running **`scripts/post-merge-smoke.sh`** (replaces inline smoke):

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

Post-merge 完整驗收（§B5，merge + deploy 後 AI/CEO 執行）：

```bash
cd ~/alltrue && git pull origin main
bash scripts/post-merge-smoke.sh
```

可選：`.cursor/.local/smoke.env`（gitignore）放置 `SMOKE_TEACHER_LOGIN` 等；未設定時腳本從 Pi 讀最新有效 session token（唯讀，不寫入 DB）。

## Failure Handling

- Smoke failure in `deploy.yml` triggers existing rollback branch.
- After rollback, deployment exits failed for operator follow-up.

## Security Notes

- Never print passwords.
- Use repo/org secrets for credentials, not plaintext in workflow files.
- Keep smoke account scoped to minimum read permissions.
