# Bug Fix Plan: 課表 / 行事曆載入速度過慢

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P2 |
| 根因類型 | 效能 |
| 根因摘要 | `SmartCalendar.vue` 在 `loadCourses()` 中 REST API 成功後仍執行 legacy Supabase fallback，且初次掛載將課程、學生、老師、教室、科目分散觸發；行事曆資料抓取窗口為目前週 ±42 天，導致 `/class-sessions` 單次回傳過大。 |
| 錯誤行為 | 切換週次或初次進入 SmartCalendar 時，前端送出不必要請求與大範圍資料查詢，正式站木柵分校 85 天 `/class-sessions` 實測約 7.5 秒。 |
| 預期行為 | REST 成功即不跑 fallback，獨立參考資料並行載入，週檢視只預抓足夠支援相鄰週的資料。 |
| 影響範圍 | 主任 / 老師的 `SmartCalendar` 初次載入、切週、切分校。 |
| B1 偵查來源 | GitHub #358、`SmartCalendar.vue`、正式站 read-only API timing。 |

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | calendar-load-performance |
| 版本 | 2026-05-16 |
| 狀態 | Draft - 等待使用者批准 DEV |
| 嚴重度 | P2 |
| 目標角色 | director / teacher |
| 關聯 Bug | GitHub #358 |

## 2. 業務背景與影響

行事曆是主任排課、調課、代課與老師看課表的核心頁。功能可用但體感慢，會放大使用者重複點擊與誤判「系統卡住」的機率。

修復後預期：初次載入和切週減少多餘 request 與 payload，載入條更快結束；既有顯示、代課、請假、調課語意不變。

## 3. 範圍

In Scope:
- `SmartCalendar.vue`：REST 成功時跳過 legacy fallback。
- `SmartCalendar.vue`：初次掛載與分校切換時並行載入互不依賴的參考資料。
- `SmartCalendar.vue`：把 `getCalendarDataFetchBoundsYmd()` 從 ±42 天縮到 ±21 天。
- 前端 regression：覆蓋 fallback gating 與 fetch window helper。

Out of Scope:
- 不改 `ClassSessionController` SQL join / indexing。
- 不改 `schedules` / `class-sessions` API contract。
- 不改 calendar occurrence merge 規則、代課權限、出缺勤寫入。
- 不做 DB migration。

## 4. RACI

| 類別 | R | A | C | I |
|---|---|---|---|---|
| B1 / DEV / TEST / REVIEW / DOCS / OPS | AI Agent | AI Agent | 使用者 | 使用者 |

## 4b. Dependencies

無前置 PR、無 migration。需保留最近新增的 SmartCalendar 代課 / 去重 regression 行為。

## 5. Acceptance Criteria

### AC-001：REST 成功不再執行 fallback
- AC-001-a：`student-classes` REST 成功且回傳資料時，`SmartCalendar` 不再查 legacy `supabase.from('student-classes')`。
- AC-001-b：`schedules` REST 成功且回傳資料時，`SmartCalendar` 不再查 legacy `supabase.from('schedules')`。
- AC-001-c：REST 失敗或無資料時，既有 fallback 仍可讓頁面保持可用。

### AC-002：載入範圍縮窄
- AC-002-a：週檢視資料窗口為目前渲染週前後各 21 天。
- AC-002-b：切到相鄰週時仍有足夠資料支援週顯示與代課/請假合併。

### AC-003：初始資料並行載入
- AC-003-a：初次掛載以單一 orchestrator 並行觸發課程、學生、老師、教室、科目載入。
- AC-003-b：切分校時課程、學生、老師、教室可並行重抓，不阻塞彼此。

### AC-004：既有行事曆語意不回歸
- AC-004-a：`npm run test:calendar` 維持通過。
- AC-004-b：代課老師顯示、同學生同時段去重、請假優先規則維持通過。

## 6. 功能需求 FR

- FR-001：REST API 已成功提供 primary data 時，系統應避免執行 legacy fallback request。
- FR-002：週檢視 data prefetch 應限制在目前週 ±21 天。
- FR-003：初次掛載與分校切換應並行載入互不依賴資料。
- FR-004：任何效能調整不得改變 occurrence merge 的顯示結果。

## 7. 非功能需求 NFR

- NFR-001：木柵分校 `class-sessions` payload 目標由 85 天約 758 筆降至 43 天約 453 筆等級。
- NFR-002：正式站 API read-only probe 需顯示縮窄範圍較原範圍 rows / bytes 減少。
- NFR-003：前端 build 與 calendar regression 必須通過。

## 8. 技術方向

- `frontend/src/pages/SmartCalendar.vue`
  - `loadCourses()`：只有在 REST 未成功取得 primary data 時才執行 `student-classes` fallback；同理 `schedules` fallback。
  - `getCalendarDataFetchBoundsYmd()`：保留週檢視中心算法，將 buffer 改為 21 天。
  - 新增小型 `loadCalendarInitialData()` / `reloadBranchCalendarData()` orchestrator，使用 `Promise.allSettled` 平行載入，不讓單一輔助資料失敗中斷整頁。
- 測試方向：若現有 Vue component 不易直接單測，抽出純 helper 到 `frontend/src/lib/calendarLoadWindow.js`，用 node test 覆蓋窗口計算與 fallback 判定。

## 8b. Decision Log

| 日期 | 決策 | 替代方案 | 選擇理由 |
|---|---|---|---|
| 2026-05-16 | 先做前端縮量與去重複請求 | 直接改後端 SQL / 加 index | 前端問題已明確、低風險、不需 migration；後端 SQL 牽涉 class-session 顯示與代課 join，另案評估較安全。 |
| 2026-05-16 | buffer 採 ±21 天 | 只抓當週 / 保留 ±42 天 | 當週太窄可能影響切週與相鄰週體驗；±21 天可涵蓋相鄰三週並明顯減 payload。 |
| 2026-05-16 | fallback 保留但 gated | 移除 fallback | 保留 API 失敗時可用性，避免一次刪除 legacy path 帶來不可預期空白頁。 |

## 9. 資安與存取控制

不新增端點、不改 auth / middleware / PII 欄位。既有 request 仍使用 Bearer token 與後端 `require_campus` / role scope。老師週檢視仍需載入足夠同校區代課 exception，再由 occurrence merge 依 teacher scope 過濾。

## 10. QA 驗收

Happy Path:
- 主任開木柵週行事曆，課程與代課/請假顯示正常，載入完成。
- 老師開週行事曆，代課後原老師/代課老師可見性與既有 regression 一致。

Edge:
- REST `student-classes` 或 `schedules` 失敗時 fallback 路徑仍可填資料。
- 無分校 / teacher mode 條件仍不發無效大查詢。

Error:
- 任一參考資料 API 失敗時不讓整個 calendar 卡死。

Revert-proof 驗證:
- [ ] `git stash` 還原修法後，新增 fallback/window 測試至少一個 case failure。

## 11. 上線與維運

- Migration：無。
- 部署：前端變更，走 feature branch PR；CI 綠後 merge，由 `deploy.yml` 自動部署。
- Observability：部署後查 `version.json` 更新、`/api/v1/health` 200；可重跑 read-only API timing 比較 rows / bytes。
- 回滾：若行事曆顯示異常，以 `git revert <merge_commit>` 回滾，預估 10-15 分鐘；不涉及 DB rollback。

## 12. 優先級

P2。執行 Agent:
- `[DEV]` 前端修復
- `[TEST]` regression + revert-proof
- `[REVIEW]` 對照 FR / 行事曆已知坑
- `[DOCS]` CHANGELOG + AI_REGRESSION_LESSONS
- `[OPS]` CI / deploy / health

## 13. 風險 / 假設 / 開放問題

WebSearch 結論：行事曆效能常見作法是避免重複 API request、用可覆蓋可見區間的 cache/window、只抓 visible range 附近資料、參考資料並行載入；開源/產品案例也常見 backend filtering、lazy loading、slot caching。

風險:
- ±21 天若仍有特殊 UI 操作需要更遠資料，可能造成邊界週顯示不完整；需用現有 `test:calendar` 和手測切週覆蓋。
- `teachers/students` 可能在 `loadCourses()` enrich exception name 之前尚未完成；現有 course map 已可提供主要名稱，並行後需確認 name fallback 不回歸。
- 後端 `/class-sessions` 單次 43 天仍約 5 秒，若前端縮量後體感仍不夠，下一階段需評估 SQL / index / API response slimming。

開放問題:
- 是否需要後續把 `/class-sessions` response 拆為 calendar-lite endpoint？本次不做。

## 14. Definition of Done

- [ ] FR-001：驗證方式：新增前端單測或 helper 測試，REST 成功時 fallback predicate 回傳 false。
- [ ] FR-002：驗證方式：新增前端單測，固定某週輸入時 `schedStart/schedEnd` 為週一前 21 天、週日前 0 天後 21 天。
- [ ] FR-003：驗證方式：`rg "Promise.allSettled|Promise.all" frontend/src/pages/SmartCalendar.vue` 顯示 mount / branch reload orchestrator。
- [ ] FR-004：驗證方式：`cd frontend && npm run test:calendar` 通過。
- [ ] Build：驗證方式：`cd frontend && npm run build` 通過。
- [ ] Revert-proof：驗證方式：`git stash && cd frontend && npm run test:calendar` 或新增測試命令至少一個 case failure，再 `git stash pop`。
- [ ] CHANGELOG：驗證方式：`git diff docs/CHANGELOG.md` 含 2026-05-16 `fix(calendar)` 條目。
- [ ] Health check：驗證方式：部署後 `curl -sk https://daan.lifenet.com.tw/api/v1/health` 回傳 HTTP 200 且 `status=ok`。
