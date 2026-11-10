# Bug Fix Plan: LearningRecord 已作廢遺留 → 評量入口永久卡死（resurrect-on-write）

> GitHub #495｜in-app #125｜2026-05-23｜Owner: AI Agent

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P1 |
| 根因類型 | 邏輯錯誤（狀態回復路徑遺漏 + 入口無 self-healing） |
| 根因摘要 | `LearningRecordController::store()` 在發現同 `ClassSessionID` 已有 `VoidedAt` 列時直接回 409，但 `ClassSession` 已被回復為 attended／scheduled，且 LR `VoidReason='一般請假'` 屬於 cascade 副作用，舊 LR 沒人去清。老師就被永久擋在入口外。 |
| 錯誤行為 | 老師按「填寫評量」→ 後端回 409「此堂評量已作廢…」，無法在 UI 內補救。 |
| 預期行為 | 當對應 `ClassSession` 已恢復為 fillable 狀態（attended/scheduled/completed）且舊 LR 的 `VoidReason='一般請假'`（系統 cascade 副作用），store 入口應**自動 resurrect** 舊 LR（清 `VoidedAt/VoidedByUserID/VoidReason`、`Status='pending'`、套入本次提交內容），回 200 OK 讓老師繼續填寫。 |
| 影響範圍 | `learning` 頁面、`POST /api/v1/learning-records`；歷史曾被請假 cascade 觸碰、之後又被撤銷請假／調課／點名為出席的 LR；目前生產至少 1 筆（沈宇璿 SC#1029 / CS#8840 / LR#5295）。 |
| B1 偵查來源 | 本計畫整合 B1 內容（in-app #125 截圖 #87 + DB trace `LearningRecord.VoidReason='一般請假'` + CS#8840 `Status=attended, Note=leave`）。 |

## 1. 文件資訊

| 欄位 | 值 |
|---|---|
| 功能名稱 | LearningRecord 入口 self-healing（已作廢遺留復原） |
| 版本 | 1.0 |
| 狀態 | Draft → Approved |
| 嚴重度 | P1 |
| 目標角色 | teacher（主要）、director（次要） |
| 關聯 Bug | GitHub #495、in-app #125 |

## 2. 業務背景與影響

- **痛點**：請假 cascade → 撤銷請假後（或調課／點名為出席），評量 LR `VoidedAt` 沒被清掉，老師再回去填評量永遠擋在「此堂評量已作廢」409。需手動進 DB 解決，違反 P0 R2（禁止在 Pi 跑高風險指令）。
- **修復後預期行為**：老師對 attended/scheduled/completed 堂次提交評量時，後端自動判定 cascade 殘留可 resurrect 並回 200，不再請主任手動處理。
- **不在範圍**：刻意「人工作廢」（非 cascade `VoidReason`）仍維持 409，避免覆寫管理員決策。

## 3. 範圍

**In Scope**
- 修改 `LearningRecordController::store()` voided 409 分支：當 `VoidReason='一般請假'` 且 CS 為 fillable 狀態，resurrect 舊 LR 並回 200。
- 新增 PHPUnit 測試：cascade-void + CS revived 後 store 路徑 → 200，DB `VoidedAt` 為 null、`Status='pending'`、`Content` 來自新提交。
- CHANGELOG + AI_REGRESSION_LESSONS 新增規則。

**Out of Scope**（明確不動）
- `CourseLeaveCascadeService::applyLeaveCascade` / `undoLeaveCascade`（不調整 cascade 寫入邏輯）
- 前端 `LearningRecordsPage.vue`（後端 200 之後前端原本流程就會繼續）
- `update()` / approve()／reject() 路徑
- `VoidReason` 非「一般請假」之列（保留 manual void 行為）
- in-app #124（duplicate cancelled CS）／#126（堂數未顯示）— 各自獨立 issue／PR

## 4. RACI

| 角色 | R | A | C | I |
|---|---|---|---|---|
| AI Agent | ✅ | ✅ | — | — |
| 使用者 | — | — | — | ✅ |

## 4b. Dependencies

- 無前置 PR / migration；單純 controller 邏輯 + 測試。

## 5. Acceptance Criteria

### AC-001：cascade-void LR + CS 已 attended → store 自動 resurrect
- AC-001-a：teacher POST `/api/v1/learning-records` with `ClassSessionID=X`，X 的 LR 已有 `VoidedAt` 且 `VoidReason='一般請假'`，CS.Status='attended' → 回 **200**（或 201），response body 含同一 LR `id`。
- AC-001-b：DB 內該 LR `VoidedAt=NULL`、`VoidReason=NULL`、`Status='pending'`、`Content`／`Progress` 等取自本次 request。

### AC-002：cascade-void LR + CS 仍為 leave/cancelled → 維持 409 voided
- AC-002-a：CS.Status='leave' 時，store 回 **409** 含 `voided: true`（避免在請假未撤銷前重新建立評量）。
- AC-002-b：CS.Status='cancelled' 時，store 回 **409** 含 `voided: true`。

### AC-003：人工作廢（非 cascade）不被覆寫
- AC-003-a：LR `VoidedAt` 不為 null 但 `VoidReason` ≠ '一般請假'（例如管理員手動作廢）→ 維持 **409** `voided: true`，**不 resurrect**。

### AC-004：正常已存在未作廢 LR 行為不變
- AC-004-a：LR `VoidedAt=NULL` 且已存在 → 維持原 409 `此堂評量表已存在` 行為。

## 6. 功能需求 FR

- **FR-001**：`store()` 偵測 `$rowForSession->VoidedAt` 非空且 `VoidReason='一般請假'` 且 `$classSession->Status` 屬 fillable 集合（`attended`、`scheduled`、`completed`、`late`）時，更新該 LR：清 `VoidedAt`、`VoidedByUserID`、`VoidReason`，套入 `Content/Progress/HomeworkStatus/...` 等本次 request 欄位，`Status='pending'`，並回 200。
- **FR-002**：FR-001 不滿足時（CS 仍為 leave/cancelled，或 `VoidReason` 非 cascade）→ 維持現有 409 `voided: true` 行為。
- **FR-003**：未作廢但已存在 → 維持現有 409「此堂評量表已存在」行為。
- **FR-004**：resurrect 路徑保留審計：`updated_at` 由 Eloquent 自動更新；不寫新 LR 列。

## 7. 非功能需求 NFR

不適用（純後端條件分支邏輯，無效能/延遲變化）。

## 8. 技術方向（禁止 code）

**檔案：`backend/app/Http/Controllers/LearningRecordController.php`**
- 方法：`store()` 中 line 853-874 的 `$rowForSession` 區塊
- 介入點：發現 `VoidedAt` 非空時，新增條件分支判斷 `VoidReason` 與 `$classSession->Status`。命中即更新該 LR 後 return 200；否則維持 409。
- 集合 `fillable_statuses = {attended, scheduled, completed, late}` 寫成 controller private const，避免散落字面值。

**取捨**：
- A. 在 store 入口 self-heal：✅ blast radius 小、不改 cascade 既有行為、所有歷史殘留自然被治癒。
- B. 在 `CourseLeaveCascadeService::undoLeaveCascade` 額外清 `VoidedAt`：只能修「未來」undo，舊資料還是壞著。
- C. 在 `ClassSession` 觀察者：複雜、跨多模組副作用。

選 A，原因見 §8b Decision Log。

## 8b. Decision Log

| 日期 | 決策 | 替代方案 | 理由 |
|---|---|---|---|
| 2026-05-23 | 在 `store()` 入口 self-heal | 改 cascade undo / 加觀察者 | 一處修復涵蓋歷史殘留 + 未來事件，blast radius 最小 |
| 2026-05-23 | 只 resurrect `VoidReason='一般請假'` | 全部 voided 都 resurrect | 不覆寫人工作廢（admin 決策需保留） |
| 2026-05-23 | fillable 集合含 `late` | 只 `attended/scheduled/completed` | 與 `LearningRecord::scopeExcludeLeaveSessionPendingReview` 反向集合對齊 |
| 2026-05-23 | resurrect 後回 200 + 同一 LR id | 回 201 / 新建一筆 | 保留歷史 id（連結既有評論／審核流程）；不破壞 unique constraint |

## 9. 資安與存取控制

- store() 既有 `auth_role` / `auth_campus_ids` / `teacherAllowedToCreateLearningRecord` 檢查不變。
- resurrect 不繞過任何權限：仍需通過時間 gate（`validateSessionStartedForWrite`）。
- 不擴大 UI／API 表面；不暴露 PII；不涉及 LINE webhook／RFID。
- 因不涉及 auth 邊界擴張、且只修補同表既有列，**[REVIEW] SEC 不需單獨 STRIDE pass**（規則第 111 行條件均不滿足）。

## 10. QA 驗收

### Happy Path
- AC-001：cascade-void + CS attended → 200，DB resurrected。

### Edge
- AC-002a/b：CS leave / cancelled → 409 voided。
- AC-003：`VoidReason='手動作廢'` → 409 voided。

### Error
- AC-004：未作廢已存在 → 409「此堂評量表已存在」。

### Revert-proof 驗證
- [ ] `git stash` 後重跑新測試，AC-001 應 fail（確認新測試覆蓋的是本次修復）。

## 11. 上線與維運

- **部署**：feature branch PR merge → `deploy.yml` 自動部署（無 frontend、無 migration）。
- **Migration**：無。
- **Observability**：可選後續加 `Log::info('[LR][resurrect]', ['lr_id'=>...])`，本次 PR 不加。
- **回滾**：`git revert <commit>` + `deploy.yml` 重跑；無 schema 異動 → 5 分鐘內可回滾。

## 12. 優先級

- **P1**（影響日常評量填寫；reporter 高優先回報）
- 執行 Agent：`[DEV]`（控制器修改 + 測試）

## 13. 風險 / 假設 / 開放問題

- 假設 `VoidReason='一般請假'` 是 cascade 唯一的副作用作廢來源（搜尋 `backend/app/Services/CourseLeaveCascadeService.php` 確認；無其他 service 使用此字串）。
- **業界參考（WebSearch & 內部記憶）**：
  - GitHub Issues／Linear／Jira 採用 **soft-delete + restore-on-recreate** 模式（同概念）。
  - Stripe Idempotency Key：同 key 重發以回傳已存在資源代替錯誤 → 對齊本 PR「同 ClassSessionID 自動接續」。
  - Laravel 官方 SoftDeletes `restore()`：本系統 LR 用自製 `VoidedAt` 而非 SoftDeletes trait；本次補上的 self-heal 等價於 restore + reset。
  - 內部 MemPalace：`AI_REGRESSION_LESSONS` §R39（評量／調課作廢未對齊）已記錄相同類別坑。
- **開放問題**：是否需要對 director path 加 audit log（記下 resurrect 動作）？→ 本 PR 不做，登 TD。

## 14. Definition of Done

- [ ] FR-001／FR-002／FR-003：驗證方式：`vendor/bin/phpunit --filter=LearningRecordVoidedResurrectTest` 4 個 case 全綠
- [ ] Revert-proof：驗證方式：`git stash && vendor/bin/phpunit --filter=test_resurrect_voided_when_session_attended` 至少 1 failure
- [ ] CHANGELOG：驗證方式：`git diff docs/CHANGELOG.md` 含 `2026-05-23` Fixed 條目
- [ ] AI_REGRESSION_LESSONS：驗證方式：`git diff docs/AI_REGRESSION_LESSONS.md` 含新 §R55
- [ ] CI：驗證方式：`gh run list --limit 1` → success
- [ ] Health check：`curl -sk https://daan.lifenet.com.tw/api/v1/health` → `{"status":"ok",...}`
- [ ] In-app Bug 回寫：`bug_reports.id=125 status=resolved` 且 `bug_report_comments` 有公開留言含「請至 Bug 回報頁按『確認已修好』」
