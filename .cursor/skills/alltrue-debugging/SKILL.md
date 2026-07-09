---
name: alltrue-debugging
description: >-
  AllTrue bug 除錯分診 SOP。收到 bug 回報、測試失敗、production 異常、
  in-app triage 時啟用。強制先查 AI_REGRESSION 與 production 唯讀證據再改碼。
---

# AllTrue Debugging

## 1. Purpose

系統化找出**根因**（非症狀），產出可驗證的假設與最小修復範圍。

## 2. When to activate

- In-app bug 分診或修復（`docs/CHAT_BUG_SYSTEM.md` §3.6–§3.7）
- CI 失敗、production smoke 異常
- 使用者回報「以前正常現在壞了」

## 3. Required workflow

1. **讀 INDEX** → 定位模組章節 + `AI_REGRESSION_LESSONS` 文末索引表
2. **認領復發家族**（F1–F6）— `bug-fix-plan.mdc` §B0
3. **蒐證**（至少 2 個獨立來源）：
   - production **唯讀** tinker / SQL（⛔ 禁 `php artisan test` on Pi）
   - GitHub issue / in-app 附件
   - 相關程式路徑 grep
4. **列 2–3 個根因候選** → 用證據排除至 1 個
5. **最小修復設計** → 先寫 failing test（`alltrue-testing`）
6. **資料修復？** → 寫 `docs/incidents/` 計畫，**等批准**，不直接寫 DB

## 4. Forbidden actions

- ⛔ Pi 上跑 `php artisan test` / `phpunit` / `config:clear`
- ⛔ 未讀 `AI_REGRESSION_LESSONS` 就改高風險模組（行事曆、扣堂、繳費）
- ⛔ 只修 UI 掩蓋後端資料錯誤
- ⛔ 關 GitHub issue 卻不回 in-app 留言

## 5. AllTrue-specific rules

- 多校區：每個 query 必須說明 `CampusID` / `branch_id` 隔離
- `StudentSingIn`（歷史 typo）不可改表名
- 行事曆週檢視唯一合法路徑：`calendarOccurrenceMerge.js`（G-007）
- Bug 公開留言：白話、禁欄位名/SQL（`user-facing-communication.mdc`）

## 6. Exit criteria

- [ ] 根因一句話 + 證據連結
- [ ] 影響範圍（分校/角色/資料列）
- [ ] 修復方案已對照既有 PR/issue 無重複
- [ ] 若需資料修復：incident 文件已建，狀態 Draft
