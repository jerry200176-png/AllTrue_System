# Bug Fix Plan: 調課後課程管理多顯示一堂「取消」同時段—隱藏 cancelled-duplicate-reschedule-placeholder

> GitHub #496｜in-app #124｜2026-05-23｜Owner: AI Agent

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P2 |
| 根因類型 | 邏輯錯誤（內部 placeholder 列被外露給 UI） |
| 根因摘要 | 調課流程在 `LearningRecordController::cancelAutoMaterializedDuplicateSession()` 把 `auto-materialized-from-schedule` 的同槽 placeholder 標 `Status=cancelled` + `Note .= 'cancelled-duplicate-reschedule-placeholder'`。`GET /api/v1/class-sessions` 一視同仁回給前端，UI 把它當作「使用者可見的取消堂」並計入 `cancelledSessionCount`，主任就看到「同時段多一堂取消」。 |
| 錯誤行為 | 課程管理顯示同日同時段：第 N 堂（正常） + 取消標籤（placeholder）並列；「X 堂已取消」計數也膨脹。 |
| 預期行為 | placeholder 屬內部 bookkeeping，UI 不應呈現；API 預設過濾掉 `cancelled-duplicate-reschedule-placeholder` 的列；計數與 chip 都不再多出來。需保留 audit/recover 路徑（提供 opt-in 參數）。 |
| 影響範圍 | `course-mgmt` 頁面、`POST /schedules` 調課流程後產生 placeholder 的所有 SC；至少 1 筆生產：洪家溱 SC#1837 / CS#14872。 |
| B1 偵查來源 | 本計畫整合 B1 內容（in-app #124 截圖 #86 + DB trace `ClassSession.Note='auto-materialized-from-schedule; cancelled-duplicate-reschedule-placeholder'`）。 |

## 1. 文件資訊

| 欄位 | 值 |
|---|---|
| 功能名稱 | ClassSession 內部 placeholder 預設不外露 |
| 版本 | 1.0 |
| 狀態 | Draft → Approved |
| 嚴重度 | P2 |
| 目標角色 | director（主要）、teacher（次要） |
| 關聯 Bug | GitHub #496、in-app #124 |

## 2. 業務背景與影響

- **痛點**：主任每次調課後在課程管理會看到 1 筆「同時段取消」的鬼影，誤以為系統重複建立或紀錄錯亂；計數膨脹也影響續課/堂數判讀。
- **修復後預期行為**：API 預設不回傳 placeholder，課程管理／日曆／出缺勤皆不會看到該列；audit 時可加 `include_internal_placeholder=1` 取回。
- **不在範圍**：placeholder 的建立路徑（保留原行為以維持調課內部一致性）；歷史已存在的 placeholder 不做資料 backfill（過濾即可）。

## 3. 範圍

**In Scope**
- `ClassSessionController::index()` 預設 `WHERE NOT (Status='cancelled' AND Note LIKE '%cancelled-duplicate-reschedule-placeholder%')`；提供 `include_internal_placeholder=1` opt-in。
- PHPUnit 測試：placeholder 預設不出現；opt-in 後出現；非 placeholder 的 cancelled 仍出現。
- CHANGELOG + AI_REGRESSION_LESSONS 新增規則（G-009）。

**Out of Scope**（明確不動）
- `LearningRecordController::cancelAutoMaterializedDuplicateSession`（保留現有寫入行為）
- 智慧行事曆 `mergeWeekCalendarOccurrences`（已有自身去重，沒見到回報），但仍在驗收清單跑 `npm run test:calendar` 確認無回歸
- 出缺勤點名／RFID 路徑（不涉及）
- in-app #125（評量）／#126（堂數未顯示）— 各自 PR
- 前端 `useCourseSessionsDisplay.cancelledSessionCount`（後端過濾後即正確；不額外加前端 guard，避免「過濾兩次」）

## 4. RACI

| 角色 | R | A | C | I |
|---|---|---|---|---|
| AI Agent | ✅ | ✅ | — | — |
| 使用者 | — | — | — | ✅ |

## 4b. Dependencies

無前置 PR／migration；單一 controller query 條件 + 測試。

## 5. Acceptance Criteria

### AC-001：placeholder 預設不出現於 `GET /api/v1/class-sessions`
- AC-001-a：director 呼叫 `/api/v1/class-sessions?student_class_id=X` 回傳列表，不含 `Note` 含 `cancelled-duplicate-reschedule-placeholder` 的列。
- AC-001-b：同呼叫的 `total` 計數不計入該列。

### AC-002：人工 cancelled 仍照常出現
- AC-002-a：`Status='cancelled'` 且 `Note` 不含 placeholder 字串的列，仍正常回傳。

### AC-003：opt-in audit
- AC-003-a：加上 `include_internal_placeholder=1` 後，placeholder 列恢復出現。

## 6. 功能需求 FR

- **FR-001**：`ClassSessionController::index()` 在 query 加 `where(function ($q) { $q->where('cs.Status', '<>', 'cancelled')->orWhere('cs.Note', 'NOT LIKE', '%cancelled-duplicate-reschedule-placeholder%'); })`，並以 `include_internal_placeholder` query param 反轉條件。
- **FR-002**：不影響其他 query param 行為（status filter、date range、teacher_id 等）。
- **FR-003**：opt-in 路徑保留給 audit／QA／後台診斷工具。

## 7. 非功能需求 NFR

- 不顯著影響查詢計畫：條件加在 `WHERE cs.Status='cancelled'` 同等過濾位置；既有 `cs` 索引（Status+SessionDate）仍有效。

## 8. 技術方向（禁止 code）

**檔案**：
- `backend/app/Http/Controllers/ClassSessionController.php`：`index()` 加入過濾條件 + `include_internal_placeholder` 開關
- `backend/tests/Feature/ClassSessionPlaceholderHideTest.php`（新）

**取捨**：
- A. 後端過濾（單一資料源真相）：✅ 所有前端／日曆／API consumer 一致；歷史資料自然乾淨。
- B. 前端 useCourseSessionsDisplay 過濾：分散邏輯，calendar/teacher view 仍可能漏；多入口要重複修補。
- C. 不建立 placeholder：改 cancelAutoMaterializedDuplicateSession 改用 delete()，喪失 audit 線索。

選 A。

## 8b. Decision Log

| 日期 | 決策 | 替代方案 | 理由 |
|---|---|---|---|
| 2026-05-23 | 後端 index 過濾 + opt-in | 前端過濾 / DB backfill | 一處改全部消費端乾淨；可逆 |
| 2026-05-23 | 保留 placeholder 寫入 | 直接 delete | 留 audit；未來若需要追蹤 reschedule chain 仍可查 |

## 9. 資安與存取控制

不涉及 auth / PII；條件僅作 row visibility 過濾。

## 10. QA 驗收

### Happy Path
- AC-001：placeholder 不出現。

### Edge
- AC-002：非 placeholder cancelled 仍出現。
- AC-003：opt-in 顯示。

### Regression
- 跑 `npm run test:calendar` 確認週檢視 occurrence merge 不被影響。

### Revert-proof 驗證
- [ ] `git stash` 後重跑 `ClassSessionPlaceholderHideTest::test_placeholder_hidden_by_default` 應 fail。

## 11. 上線與維運

- 部署：PR merge → `deploy.yml`；無 migration、無 frontend。
- 回滾：`git revert`；無 schema 異動，5 分鐘可回滾。

## 12. 優先級

- **P2**（影響課程管理顯示信心；不阻斷核心流程）
- 執行 Agent：`[DEV]`

## 13. 風險 / 假設 / 開放問題

- 假設 `cancelled-duplicate-reschedule-placeholder` 是該 placeholder 唯一識別字串（grep 確認 source/test 各 1 處）。
- **業界做法（WebSearch & 內部記憶）**：
  - GitHub PR 預設不顯示 ghost commits / draft refs；提供 `?show=all` opt-in。
  - Kubernetes 物件預設不顯示 internal finalizer pods；用 `--show-kind=internal` opt-in。
  - 內部 `AI_REGRESSION_LESSONS` G-007 已警告日曆 occurrence merge 要走 resolver；本 PR 補上「同源資料 placeholder 不應外露」的反面。
- **開放問題**：是否需要 daily nightly 任務硬刪除超過 90 天的 placeholder？→ 登 TD-NNN，本 PR 不做。

## 14. Definition of Done

- [ ] FR-001／FR-002／FR-003：`vendor/bin/phpunit --filter=ClassSessionPlaceholderHideTest` 3 case 全綠
- [ ] Revert-proof：`git stash && vendor/bin/phpunit --filter=test_placeholder_hidden_by_default` 至少 1 failure
- [ ] CI：`gh run list --limit 1` → success
- [ ] CHANGELOG / AI_REGRESSION_LESSONS：`git diff` 有 2026-05-23 條目
- [ ] Health check：`curl -sk https://daan.lifenet.com.tw/api/v1/health` → ok
- [ ] In-app Bug 回寫：`bug_reports.id=124 status=resolved` + 公開留言
- [ ] `npm run test:calendar` 通過
