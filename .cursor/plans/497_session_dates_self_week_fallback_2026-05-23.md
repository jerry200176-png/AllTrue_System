# Bug Fix Plan: 課程管理堂數未顯示—`session-dates` POST 漏讀自身 `week*` 欄位

> GitHub #497｜in-app #126｜2026-05-23｜Owner: AI Agent

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P2 |
| 根因類型 | 邏輯錯誤（fallback 鏈遺漏自身欄位） |
| 根因摘要 | `StudentClassController::sessionDates()` POST 路徑：前端送的 `days_of_week=[]` → 走 `buildPackageFallbackDaysMap`，但該 helper **只填補 sibling 為空的課**（line 950 `if (empty($daysByClass[$cid]))`），如果本課自身 `week=1` 已有值就不會進 fallback 表 → 最後 `daysOfWeek=[]` → 不進 `computeEffectiveSessionDates` → 只回傳已實體化的 `ClassSession` 日期。 |
| 錯誤行為 | 施景媛 SC#1841：`SessionCount=24`，`week=1`，但前端拿到的 `effective_dates` 只有 1 筆已上日期，後續週期堂次完全消失。 |
| 預期行為 | 自身 `week, week1..week6` 任一非空時，POST 路徑應自動採用該值，計算完整 24 堂週期日期。 |
| 影響範圍 | 課程管理「堂數顯示」；所有 `payment_type='session'` 月度課 + 前端 `days_of_week=[]` + 自身 `week*` 有值的 SC。生產實例：施景媛 SC#1838–#1841（PackageID=97）。 |
| B1 偵查來源 | 本計畫整合 B1 內容（in-app #126 + DB trace `week=1`、`SessionCount=24`、`cs_cnt=2`）。 |

## 1. 文件資訊

| 欄位 | 值 |
|---|---|
| 功能名稱 | sessionDates POST 補上自身 `week*` fallback |
| 版本 | 1.0 |
| 狀態 | Draft → Approved |
| 嚴重度 | P2 |
| 目標角色 | director（主要） |
| 關聯 Bug | GitHub #497、in-app #126；延伸已修 #440／PR #446 |

## 2. 業務背景與影響

- **痛點**：主任建立 24 堂課程後，課程管理只看到 1–2 堂歷史日期，後續週期完全消失；以為「設定遺失」實際只是顯示漏推算。
- **修復後預期行為**：sessionDates POST 在 bodyCourses 未夾帶 days_of_week 時，依優先序回退：(1) request `days_of_week`；(2) package sibling fallback（#440）；(3) **本課自身 week 欄位（本 PR 新增）**。

## 3. 範圍

**In Scope**
- `StudentClassController::sessionDates()` POST 路徑：補上自身 `week*` 欄位 fallback（在 sibling fallback 之後）。
- PHPUnit 測試：`SessionDatesSelfWeekFallbackTest`，覆蓋 #126 場景與 #440 既有 sibling fallback 不受影響。

**Out of Scope**（明確不動）
- `buildPackageFallbackDaysMap`（原行為保持，sibling 補空白機制不變）
- GET 路徑（read `week*` 是另一個分支，#440/#446 已涵蓋；本 PR 只補 POST 漏鏈）
- 前端 `useCourseSessionsDisplay`（後端修好後前端自動正確）
- in-app #124／#125 — 各自 PR

## 4. RACI

| 角色 | R | A | C | I |
|---|---|---|---|---|
| AI Agent | ✅ | ✅ | — | — |
| 使用者 | — | — | — | ✅ |

## 4b. Dependencies

無；單一 controller 條件分支 + 測試。

## 5. Acceptance Criteria

### AC-001：自身 `week=1` 有值 + bodyCourses days_of_week 空 → 推算完整 24 堂
- AC-001-a：POST `/api/v1/student-classes/session-dates`，body 包 courses[{id: X, first_class_date: 2026-05-18, sessions_purchased: 24, days_of_week: []}]，X 自身 `week=1` → 回傳 24 筆星期一日期。

### AC-002：#440 sibling fallback 仍正常
- AC-002-a：自身 `week*` 全 NULL，但同 package sibling 有 `week=1` → 仍回傳 24 筆。

### AC-003：request days_of_week 優先
- AC-003-a：bodyCourses days_of_week=[3]，自身 week=1 → 採用 [3]（request 優先）。

## 6. 功能需求 FR

- **FR-001**：bodyCourses 處理時，`daysOfWeek` 解析優先序：request → sibling fallback → 自身 `week*`。
- **FR-002**：所有 fallback 取得結果都需通過原有 `[1..7]` 範圍驗證 + sort。

## 7. 非功能需求 NFR

不適用（純條件分支，無 N+1）。

## 8. 技術方向（禁止 code）

**檔案**：
- `backend/app/Http/Controllers/StudentClassController.php`：`sessionDates()` POST 段落，緊接在 sibling fallback 後加自身 `week*` 讀取。
- `backend/tests/Feature/SessionDatesSelfWeekFallbackTest.php`（新）。

**取捨**：
- A. POST 路徑加自身 fallback：✅ 最小修改、不動 helper 共用邏輯。
- B. 修改 `buildPackageFallbackDaysMap` 讓「有自身 days」也進 fallback 表：影響 GET 路徑語意，可能有未知 regression。

選 A。

## 8b. Decision Log

| 日期 | 決策 | 替代方案 | 理由 |
|---|---|---|---|
| 2026-05-23 | 在 POST 段就近補自身 fallback | 改 buildPackageFallbackDaysMap | blast radius 小、不影響 GET 與其他呼叫者 |

## 9. 資安與存取控制

不適用（既有 branch/role/campus 檢查全保留）。

## 10. QA 驗收

### Happy Path
- AC-001：施景媛場景。

### Edge
- AC-002：純 sibling fallback（#440 場景）。
- AC-003：request 優先。

### Revert-proof 驗證
- [ ] `git stash && vendor/bin/phpunit --filter=test_falls_back_to_self_week_when_request_and_sibling_empty` 至少 1 failure。

## 11. 上線與維運

- 部署：PR merge → `deploy.yml`；無 migration、無 frontend。
- 回滾：`git revert`；5 分鐘可回滾。

## 12. 優先級

- **P2**（影響主任建立 24 堂課的顯示信心；不阻斷核心流程）
- 執行 Agent：`[DEV]`

## 13. 風險 / 假設 / 開放問題

- 假設前端在堂數制 + monthly 等情境下不一定夾帶 days_of_week（已用 grep 確認 useCourseSessionsDisplay.js 邏輯）。
- **業界參考**：
  - GraphQL Apollo：local resolver 缺值時 fall back to local cache → 同精神。
  - Laravel Default Eloquent：`firstOrFail` 配 default scope 的多層 fallback。
- **開放問題**：是否要在前端也夾帶 `week`／`days_of_week`？→ 登 TD，可在不破壞 API 合約下另作。

## 14. Definition of Done

- [ ] FR-001／FR-002：`vendor/bin/phpunit --filter=SessionDatesSelfWeekFallbackTest` 3 case 全綠
- [ ] Revert-proof：`git stash && phpunit --filter=test_falls_back_to_self_week_when_request_and_sibling_empty` 至少 1 failure
- [ ] CI：`gh run list --limit 1` → success
- [ ] Health check：`curl -sk https://daan.lifenet.com.tw/api/v1/health` → ok
- [ ] In-app Bug 回寫：`bug_reports.id=126 status=resolved` + 公開留言
- [ ] CHANGELOG／AI_REGRESSION_LESSONS 新增 2026-05-23 條目
