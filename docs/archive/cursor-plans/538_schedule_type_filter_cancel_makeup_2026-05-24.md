# Bug Fix Plan — #538 課程管理取消補課誤套一般排程

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P1 |
| 根因類型 | Query 條件錯誤 |
| 根因摘要 | `ScheduleController::index()` 未套用 `type` query filter，導致前端要求 `type=extra` 時仍回傳一般排程 |
| 錯誤行為 | 課程管理「待補課」區塊顯示一般排程，使用者點「取消補課」後被 `cancelMakeup()` 正確拒絕並看到英文 endpoint 錯誤 |
| 預期行為 | `GET /api/v1/schedules?...&type=extra&status=scheduled` 只回傳真正補課排程，一般排程不應出現在「待補課」區塊 |
| 影響範圍 | 主任 / super_admin 的課程管理頁；`GET /api/v1/schedules` list API；不影響扣堂、請假、調課寫入邏輯 |
| B1 偵查來源 | in-app #130 / GitHub #538；附件 #91；read-only production 查詢確認木柵 `scheduled` 排程多為 `normal`，且 `ScheduleController::index()` 漏 `type` filter |

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | Cancel makeup pending-list filter fix |
| 日期 | 2026-05-24 |
| 狀態 | Draft — 等待 CEO 批准 DEV |
| 目標角色 | director / super_admin |
| 關聯 Bug | in-app #130, GitHub #538 |
| 風險等級 | T2 Product workflow（課程管理 / 排程顯示） |

## 2. 業務背景與影響

主任在課程管理展開課程詳情時，頁面會顯示「待補課」。目前 API 漏套 `type=extra`，使一般排程也可能被放進待補課清單。使用者會以為可取消補課，但後端拒絕一般排程，形成「按鈕可點但永遠失敗」的錯誤體驗。

修復後，待補課區塊只顯示真正補課，主任不會對一般課堂看到取消補課按鈕。

## 3. 範圍

In Scope:

- `GET /api/v1/schedules` 支援 `type` filter。
- 新增 regression test 覆蓋 `type=extra&status=scheduled` 不回傳 `normal`。
- 前端待補課資料再做 fail-closed 過濾，只顯示 `type === 'extra'`。
- `docs/CHANGELOG.md` 記錄一行。

Out of Scope:

- 不改 `POST /api/v1/schedules/{id}/cancel-makeup` 的業務限制；它繼續拒絕非補課排程。
- 不改請假、調課、代課、扣堂流程。
- 不做資料修復；本案是顯示/API filter bug，不是資料污染。
- 不處理 #539 的換師複製月結提示（另案）。

## 4. RACI

| 角色 | 人員 |
|---|---|
| Responsible | AI Agent |
| Accountable | AI Agent |
| Consulted | CEO（使用者） |
| Informed | 使用 in-app #130 的回報者 |

## 4b. Dependencies

- 無 migration。
- 無外部服務依賴。
- 依賴現有 `CancelMakeupScheduleTest` 測試 fixture 與 `ScheduleController::index()`。

## 5. Acceptance Criteria

### AC-001：API type filter

- AC-001-a：同一課程同時有 `normal scheduled` 與 `extra scheduled` 時，呼叫 `GET /api/v1/schedules?student_course_id=<id>&type=extra&status=scheduled&per_page=all` 只回傳 `extra`。
- AC-001-b：呼叫 `GET /api/v1/schedules?student_course_id=<id>&status=scheduled&per_page=all` 不帶 `type` 時，仍可回傳所有 scheduled 排程，維持既有相容。

### AC-002：前端 fail-closed

- AC-002-a：Course Management 收到 schedules list 後，只把 `type === 'extra'` 且 `status === 'scheduled'` 的 row 放入 `pendingMakeupsByCourse`。
- AC-002-b：若後端未來再次漏 filter，前端也不顯示一般排程的「取消補課」。

### AC-003：取消補課限制不放寬

- AC-003-a：現有 `test_cannot_cancel_non_extra_schedule` 維持 422。
- AC-003-b：現有 director cancel extra makeup tests 維持 200。

## 6. 功能需求 FR

- FR-001：`ScheduleController::index()` 應支援 `type` query parameter。
- FR-002：`type` filter 應支援 comma-list，行為與 `status` filter 一致。
- FR-003：不帶 `type` 時不改變現有 list 行為。
- FR-004：前端 `fetchPendingMakeups()` 應在寫入 state 前過濾非補課排程。

## 7. 非功能需求 NFR

不適用。此修復只增加一個已索引前景很小的 equality filter，且 schedules list 已有分校 / course / status 條件；沒有新效能目標。

## 8. 技術方向

- 後端：修改 `backend/app/Http/Controllers/ScheduleController.php` 的 `index()`，在 `status` filter 附近加入 `type` filter。
- 測試：擴充 `backend/tests/Feature/CancelMakeupScheduleTest.php`，沿用既有 director token / course fixture，新增 normal + extra schedule list 測試。
- 前端：修改 `frontend/src/composables/course-management/useRescheduleAndMakeup.js` 的 `fetchPendingMakeups()`，在 `data.data` normalization 後 fail-closed 過濾。

架構取捨：主要修在後端，因 API filter 是合約來源；前端 guard 只是防止未來 API 回歸造成主任再看到錯誤按鈕。

## 8b. Decision Log

| 日期 | 決策 | 替代方案 | 理由 |
|---|---|---|---|
| 2026-05-24 | 後端 list API 加 `type` filter | 只在前端過濾 | 前端已明確送 `type=extra`，server 漏套是根因；只修前端會留下 API 合約破洞 |
| 2026-05-24 | 前端也 fail-closed | 只修後端 | 待補課是高可見操作按鈕，多一層 guard 可避免重犯 |
| 2026-05-24 | 不放寬 `cancelMakeup()` | 讓 endpoint 接受 normal 並取消 | normal 排程不是補課，放寬會破壞業務語意 |

## 9. 資安與存取控制

本案不新增端點、不改 auth middleware、不擴權。仍需確認：

- `ScheduleController::index()` 既有 `branch_id` / `auth_campus_ids` 分校限制不變。
- `cancelMakeup()` 仍只允許 director / admin / super_admin。
- 前端 guard 不可作為權限依據；後端仍為唯一授權來源。

## 10. QA 驗收

Happy Path:

- `type=extra&status=scheduled` 只回傳補課。
- 取消補課成功流程維持。

Edge:

- 不帶 `type` 的 schedules list 維持既有行為。
- `type=extra,normal` comma-list 可同時回傳兩類。
- 非 `extra` schedule 呼叫 cancel endpoint 仍 422。

Error:

- 前端收到混入的 `normal` rows 時不渲染「取消補課」。

### Revert-proof 驗證

- [ ] `git stash` 或 revert 後重跑新增 API type filter 測試，至少 `type=extra excludes normal` 失敗。
- [ ] 前端 guard 若移除，對應 unit / static assertion（若新增）或 code review checklist 必須指出失效。

## 11. 上線與維運

- 無 migration。
- PR merge 後由 `deploy.yml` 自動部署（有 `backend/` + `frontend/` diff）。
- 部署後驗證：
  - `curl -sk https://daan.lifenet.com.tw/api/v1/health | python3 -m json.tool` 回 `status=ok`。
  - `bash scripts/post-merge-smoke.sh` 通過。
  - read-only API 抽查木柵任一課程 `type=extra&status=scheduled` 不回傳 `normal`。
- 回滾：`git revert <merge_commit>` 走 PR 或 hotfix 流程；無 DB rollback。

## 12. 優先級

P1。它讓主任看到可點但必失敗的操作，且已在 production 被回報。執行 Agent：`[DEV] [TEST] [REVIEW] [DOCS] [OPS]`。

## 13. 風險 / 假設 / 開放問題

本專案依據：

- `docs/CHAT_BUG_SYSTEM.md` §3.6–3.7：已撈附件 #91、reporter 歷史、comments/status_logs，並回寫 in-app。
- `docs/AI_REGRESSION_LESSONS.md` §R51 / §R53：分診與上線後回寫不可漏。
- `docs/AI_REGRESSION_LESSONS.md` 排課 / 代課索引：本案不動調課/代課寫入，只修 list filter。

業界 / 開源參考：

- Google AIP-160：list filtering 是 API 合約，server 端應明確支援 filter 欄位，無效 filter 應明確處理。
- Kubernetes field selectors：list API 支援 server-side exact field filtering；未支援欄位不應被默默當作已套用。
- REST API 常見做法：集合查詢以 `?status=active` 這類直覺 query string filter 做精準篩選。

風險：

- `GET /schedules` 可能有其他頁面已傳 `type` 但過去被忽略；修復後它們會開始得到正確子集合。這是預期行為，但需看測試/煙測。
- 前端 guard 若用大小寫嚴格比較，歷史資料若有 `Extra` 會被排除；目前 DB 與程式皆使用小寫 `extra`。

開放問題：

- 無需使用者決策。

## 14. Definition of Done

- [ ] FR-001/FR-002：驗證方式：新增 PHPUnit 測試呼叫 `GET /api/v1/schedules?...&type=extra&status=scheduled&per_page=all`，預期不含 `type=normal`。
- [ ] FR-003：驗證方式：新增 PHPUnit 測試不帶 `type` 時仍回傳 normal + extra scheduled。
- [ ] FR-004：驗證方式：`git diff frontend/src/composables/course-management/useRescheduleAndMakeup.js` 顯示 `pendingMakeupsByCourse` 寫入前過濾 `type/status`。
- [ ] Existing regression：驗證方式：PR CI `PHPUnit Feature & Unit Tests` success。
- [ ] Revert-proof：驗證方式：revert `ScheduleController::index()` type filter 後新增 API test 至少 1 case fail。
- [ ] CHANGELOG：驗證方式：`git diff docs/CHANGELOG.md` 含 2026-05-24 #538 條目。
- [ ] Deploy：驗證方式：deploy workflow success + `/api/v1/health` HTTP 200 `status=ok`。
- [ ] In-app Bug：驗證方式：production `bug_reports.id=130` 狀態為 `resolved`，且公開留言請回報者按「確認已修好」。
