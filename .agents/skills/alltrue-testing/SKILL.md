---
name: alltrue-testing
description: >-
  AllTrue 回歸測試 SOP。修改既有 backend/frontend production 檔前必啟用。
  RED→GREEN、實際使用路徑驗證、Factory、NOT NULL、時間敏感案例。
---

# AllTrue Testing

## 1. Purpose

用能重現失敗的測試確認修正，並在 GitHub Actions 核對交付版本的結果。可先用本機隔離環境跑針對本次改動的檢查。

## 2. When to activate

- 修改 `backend/app/`、`frontend/src/` 既有檔（P0 R1 例外：新 test / migration / Export class）
- 新增 API 行為或 session 狀態邏輯
- 修復 in-app bug 的 DEV phase

## 3. Required workflow

1. **RED**：寫測試反映 bug 行為 → push → 確認 CI **失敗**（或本機 `vendor/bin/phpunit` / `npm run test:*` 在 WSL）
2. **GREEN**：最小 production 修復 → CI **通過**
3. **測試資料**：`Campus` 用 Factory；`schedules` 記 S.D.B.；future session 用 `23:00`（Y2）
4. **高風險模組**：對照 `CLAUDE.md` Y1/Y2 + 模組索引補案例

## 4. Forbidden actions

- ⛔ Pi / `/home/admin/backend/` 跑任何測試
- ⛔ 硬寫 production DB ID
- ⛔ 只測 happy path
- ⛔ 無測試直接改 production 檔（事故 F）

## 5. AllTrue-specific rules

- PHPUnit：**僅 GitHub Actions**（或 WSL `/tmp/backend-test-*` 隔離 DB）
- 前端：`sessionConsistency.test.js`、`npm run test:calendar` 依模組選擇
- 週日 slot、leave 家族、跨約重複 — 必須有專項測試（見 R64/R65/R66）

## 6. 帳務中心使用路徑

改到待處理、續報提示或帳務操作時，用既有 Playwright fixture 啟動實際 Vue 頁面，依回報操作後重新載入。從 `frontend/` 執行對應案例，例如方案提示與確認入帳：

```sh
npm run test:e2e -- -c playwright.tuition-collection.config.js -g '5494 package close|billing guide walks'
```

畫面 fixture 使用合成回應。確認資料影響時，也跑對應的 Laravel 測試。驗證提示消失時，同時比較帳單、收款與堂次，並確認不相關的待處理項目仍在。涉及重送時，比較第一次與第二次的業務結果，確認沒有重複異動。

把操作路徑、結果及測試的 commit 記在現有 PR。正式環境驗收與回報結案依 [in-app 交付流程](../alltrue-inapp-product-loop/SKILL.md) 的第 4 節。

## 7. Exit criteria

- [ ] 至少 1 個測試鎖定本次根因
- [ ] CI PHPUnit + 相關 frontend test 綠
- [ ] 測試名稱或註解含 in-app / GitHub issue 編號（內部用，不進公開留言）
