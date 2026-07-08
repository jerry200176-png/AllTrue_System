---
name: alltrue-testing
description: >-
  AllTrue 回歸測試 SOP。修改既有 backend/frontend production 檔前必啟用。
  RED→GREEN、Factory、NOT NULL、時間敏感案例。
---

# AllTrue Testing

## 1. Purpose

確保每個 bug fix 有**會先失敗再通過**的回歸測試，且只在 GitHub Actions 驗證。

## 2. When to activate

- 修改 `backend/app/`、`frontend/src/` 既有檔（P0 R1 例外：新 test / migration / Export class）
- 新增 API 行為或 session 狀態邏輯
- 修復 in-app bug 的 DEV phase

## 3. Required workflow

1. **RED**：寫測試反映 bug 行為 → push → 確認 CI **失敗**（或本機 `vendor/bin/phpunit` / `npm run test:*` 在 WSL）
2. **GREEN**：最小 production 修復 → CI **通過**
3. **測試資料**：`Campus` 用 Factory；`schedules` 記 S.D.B.；future session 用 `23:00`（Y2）
4. **高風險模組**：對照 `module-test.mdc` + 模組索引補案例

## 4. Forbidden actions

- ⛔ Pi / `/home/admin/backend/` 跑任何測試
- ⛔ 硬寫 production DB ID
- ⛔ 只測 happy path
- ⛔ 無測試直接改 production 檔（事故 F）

## 5. AllTrue-specific rules

- PHPUnit：**僅 GitHub Actions**（或 WSL `/tmp/backend-test-*` 隔離 DB）
- 前端：`sessionConsistency.test.js`、`npm run test:calendar` 依模組選擇
- 週日 slot、leave 家族、跨約重複 — 必須有專項測試（見 R64/R65/R66）

## 6. Exit criteria

- [ ] 至少 1 個測試鎖定本次根因
- [ ] CI PHPUnit + 相關 frontend test 綠
- [ ] 測試名稱或註解含 in-app / GitHub issue 編號（內部用，不進公開留言）
