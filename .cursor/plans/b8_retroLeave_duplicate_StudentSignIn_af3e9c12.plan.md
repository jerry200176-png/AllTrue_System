# Bug Fix Plan：retroLeave 補請假重複 INSERT StudentSignIn 導致 500

---

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P1 |
| 根因類型 | 邏輯錯誤 |
| 根因摘要 | `ScheduleController::retroLeave` 在 void 舊 StudentSignIn 後，以 `create()` 新增 Status='leave' 的記錄，但 `studentsingin_classsessionid_unique` 唯一約束不區分 VoidedAt，導致一個 ClassSessionID 不能有兩筆記錄（無論是否已作廢）|
| 錯誤行為 | 對已有 StudentSignIn（已出席）的堂次執行補請假時，void 後再 INSERT 新記錄，觸發 `SQLSTATE[23000]: Duplicate entry for key studentsingin_classsessionid_unique`，回傳 HTTP 500 |
| 預期行為 | void 原有出席記錄後，應以 `updateOrCreate` 更新（或建立）同 ClassSessionID 的記錄為 Status='leave'，不新增第二筆 |
| 影響範圍 | 主任/管理員執行補請假（已出席 → 改請假）；`POST /api/v1/schedules/retro-leave` |
| B1 偵查來源 | 本計畫整合 CI 錯誤訊息 + 程式碼直讀（`ScheduleController.php` L452–470） |

---

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | retroLeave 補請假 |
| 版本 | v1 |
| 狀態 | Draft |
| 嚴重度 | P1 |
| 目標角色 | 主任（director）、管理員（admin） |
| 關聯 Issue | GitHub #2 |
| 關聯測試 | `ScheduleLeaveCascadeTest::test_retro_leave_voids_attendance_and_reverses_deduction` |

---

## 2. 業務背景與影響

### 痛點

主任對已出席課堂執行「補請假」時，系統 500 並 rollback 整筆 transaction，導致：
- StudentSignIn 未 void
- LearningRecord 未 void
- SessionDeductionLedger 未沖回
- ClassSession.Status 未改為 `leave_adjusted`

所有變更一律失敗，教師出席記錄殘留，家長繳費計算錯誤。

### 修復後預期行為

- 補請假成功回傳 200
- 原有出席 StudentSignIn 被 void（`VoidedAt` 填入）
- 同一 `ClassSessionID` 有且只有一筆 Status='leave' 的 StudentSignIn（更新或新增）
- SessionDeductionLedger 沖回、ClassSession 標記 `leave_adjusted`

---

## 3. 範圍

### In Scope
- `ScheduleController::retroLeave` 中 `StudentSignIn::create()` 改為 `updateOrCreate()`
- 同步修正 `update` 時重設 VoidedAt / VoidedByUserID / VoidReason 為 null（避免新記錄仍帶 voided 狀態）
- 取消 `ScheduleLeaveCascadeTest::test_retro_leave_voids_attendance_and_reverses_deduction` 的 `markTestSkipped`

### Out of Scope
- `CourseLeaveCascadeService`（shiftAndAppendAfterLeave、applyLeaveCascade）不動
- `SessionDeductionService::reverseForSession` 不動
- LearningRecord void 邏輯不動
- `StudentSignIn` unique constraint migration 不動（不移除或放寬唯一鍵）
- 其他 Status（'scheduled' 走 applyLeaveCascade 路徑）不動

---

## 4. RACI

| 角色 | 任務 |
|---|---|
| R（執行）| AI Agent |
| A（負責）| AI Agent |
| I（知情）| 系統管理員 |

## 4b. Dependencies

無前置 PR 或 Migration 依賴。本次修改僅涉及一個方法的 Eloquent 呼叫，不需要新欄位或資料庫遷移。

---

## 5. Acceptance Criteria

### AC-001：已出席堂次補請假成功
- AC-001-a：主任對 Status='attended' 的 ClassSession 呼叫 retroLeave，API 回傳 200
- AC-001-b：執行後 `StudentSignIn::where('ClassSessionID', $id)->whereNull('VoidedAt')->where('Status', 'leave')->count()` = 1（只有一筆有效 leave 記錄）
- AC-001-c：執行後 `StudentSignIn::where('ClassSessionID', $id)->whereNotNull('VoidedAt')->count()` ≥ 1（原出席記錄已 void）

### AC-002：沖回與狀態更新正確
- AC-002-a：`SessionDeductionLedger` 新增一筆 type='retro_leave' 的沖回記錄
- AC-002-b：`ClassSession.Status` 更新為 `leave_adjusted`
- AC-002-c：`LearningRecord` 對應記錄已 void

### AC-003：冪等性（重複呼叫不炸）
- AC-003-a：對同一堂次第二次呼叫 retroLeave，API 回傳 422（`該堂已是請假/取消狀態`），不產生第二筆 leave 記錄

---

## 6. 功能需求 FR

### FR-001：updateOrCreate 取代 create
修復後，系統應以 `ClassSessionID` 為 key 執行 `updateOrCreate`：
- 若已有 StudentSignIn（含已 void）→ 更新為 Status='leave'，清除 VoidedAt/VoidedByUserID/VoidReason
- 若無 → 新增 Status='leave'

### FR-002：保留 void 判斷邏輯
修復後，只有「無有效（非 void）StudentSignIn」時才執行 FR-001 的 updateOrCreate，與原有邏輯一致。目前邏輯：
```
if (!StudentSignIn::where('ClassSessionID', $session->id)->whereNull('VoidedAt')->exists()) {
    // 執行 FR-001
}
```
此判斷應保留，僅改 `create` → `updateOrCreate`。

---

## 7. 非功能需求 NFR

不適用。本 bug 為純邏輯錯誤（一個 Eloquent 方法呼叫），修復後不影響效能基準、不增加 query 次數（`updateOrCreate` = 1 SELECT + 1 UPDATE/INSERT，與原 `create` 相同量級）。

---

## 8. 技術方向

### 涉及檔案與方法

| 檔案 | 方法 | 變更說明 |
|---|---|---|
| `app/Http/Controllers/ScheduleController.php` | `retroLeave()` | L454 的 `StudentSignIn::create([...])` 改為 `StudentSignIn::updateOrCreate(['ClassSessionID' => $session->id], [...])` |
| `tests/Feature/ScheduleLeaveCascadeTest.php` | `test_retro_leave_voids_attendance_and_reverses_deduction` | 移除 `markTestSkipped()`，恢復測試 |

### 架構取捨

使用 `updateOrCreate` 而非 `firstOrNew + save` 或 raw `INSERT ... ON DUPLICATE KEY UPDATE`：
- Laravel 8 相容（`updateOrCreate` 自 L5 起存在）
- 語義清晰：「以 ClassSessionID 找，找到就更新，找不到就建」
- 不需要修改 unique constraint
- 不需要新 migration

### 為何不移除 unique constraint？

`studentsingin_classsessionid_unique` 是防止資料雙寫的重要保護，應保留。正確做法是修應用層邏輯，不是移除 DB 約束。

## 8b. Decision Log

| 日期 | 替代方案 | 選擇理由 |
|---|---|---|
| 2026-04-22 | 移除 unique constraint | 棄用：移除會讓多個 StudentSignIn 對應同一 ClassSession，破壞其他邏輯 |
| 2026-04-22 | `insertOrIgnore` | 棄用：會靜默忽略，不更新現有記錄狀態 |
| 2026-04-22 | `updateOrCreate` | 採用：語義正確、L8 相容、不需 migration |

---

## 9. 資安與存取控制

不適用。本 bug 修復不涉及 auth / token 驗證邏輯。retroLeave 入口已有 `RequireRole` middleware 限制主任/管理員，不受本次修改影響。

---

## 10. QA 驗收

### Happy Path
- [ ] 主任對 attended 堂次補請假 → 200，StudentSignIn 只有一筆 leave 記錄

### Edge Case
- [ ] ClassSession 原本沒有任何 StudentSignIn → 新增一筆 Status='leave'（`create` 行為不變）
- [ ] 已有 voided StudentSignIn → `updateOrCreate` 更新為 leave 並清除 VoidedAt

### Error Case
- [ ] 對 leave_adjusted 堂次再次補請假 → 422（already_adjusted 判斷在更前面，不受本次修改影響）

### Revert-proof 驗證
- [ ] 在 CI 環境中，移除本次修改（git stash）後，`test_retro_leave_voids_attendance_and_reverses_deduction` 應 fail（確認測試確實覆蓋 bug）

---

## 11. 上線與維運

### 部署步驟
1. 合併 PR 到 `main`
2. `deploy-to-pi.sh` 部署後端（無 migration）
3. `sudo service php8.2-fpm reload`（清 OPcache）
4. 呼叫 `/api/internal/opcache-reset`

### Migration
無。本次不新增欄位或索引。

### Observability
Sentry 監控 `retroLeave` 不再出現 `SQLSTATE[23000]` 錯誤。

### 回滾方案
若部署後出現預期外問題，`git revert` 本次 commit 並重新部署，約 5 分鐘內可回滾。

---

## 12. 優先級

**P1** — 功能性 bug，補請假流程完全無法使用。

執行 Agent：`[DEV]` AI Agent

---

## 13. 風險 / 假設 / 開放問題

### WebSearch 查詢結果（2026-04-22）

參考：[Laravel firstOrCreate/updateOrCreate 業界最佳實踐](https://benjamincrozat.com/laravel-firstorcreate-firstornew-createorfirst-updateorcreate-updateorinsert)

業界共識：
- **`updateOrCreate`**：Laravel 8+ 語義穩定，適合「找到就改、找不到就建」場景
- **race condition**：Laravel 10.x 以上已用 `createOrFirst` 內部優化；Laravel 8 在本場景無 race concern（已在 DB transaction + `lockForUpdate` 保護下）

### 風險

| 風險 | 可能性 | 緩解 |
|---|---|---|
| `updateOrCreate` 更新到 voided 記錄時，其他關聯欄位（如 GradeID）與原記錄不一致 | 低 | update payload 明確列出所有需要設定的欄位，確保語義一致 |
| 回滾後補請假無法使用 | 極低 | 回滾前已確認 revert-proof 測試 fail，功能狀態明確 |

### 假設

- `studentsingin_classsessionid_unique` 唯一約束存在且不應移除
- 一個 ClassSession 最多對應一筆有效（非 void）StudentSignIn

### 開放問題

無。

---

## 14. Definition of Done

- [ ] **FR-001**（updateOrCreate）：`git diff app/Http/Controllers/ScheduleController.php` 含 `updateOrCreate` 且不含舊的 `StudentSignIn::create`（在 retroLeave 路徑）
- [ ] **AC-001**（補請假 200）：`ScheduleLeaveCascadeTest::test_retro_leave_voids_attendance_and_reverses_deduction` 從 skip 變 pass，CI 回傳 exit code 0
- [ ] **全測試綠**：CI `Tests: 654, Skipped: 27（少一個）, Failures: 0, Errors: 0`
- [ ] **Revert-proof**：`git stash` 後該測試 fail（至少 1 case failure）
- [ ] **CHANGELOG**：`git diff docs/CHANGELOG.md` 含 `2026-04-22` 新增條目描述本修復
- [ ] **Health check**：部署後 `curl -sk https://localhost/api/v1/alerts/tuition?branch_id=1` 回傳 HTTP 200

---

## Todos

- [ ] `[DEV]` 修改 `ScheduleController::retroLeave`：`StudentSignIn::create(...)` → `StudentSignIn::updateOrCreate(['ClassSessionID' => $session->id], [...])`，update payload 同時清除 `VoidedAt`、`VoidedByUserID`、`VoidReason`
- [ ] `[TEST]` 取消 `ScheduleLeaveCascadeTest::test_retro_leave_voids_attendance_and_reverses_deduction` 的 `markTestSkipped()`
- [ ] `[TEST]` 執行 Revert-proof 驗證（git stash → 測試 fail）
- [ ] `[REVIEW]` Code Review：逐條對照 FR-001、FR-002，確認 updateOrCreate 欄位完整
- [ ] `[DOCS]` 更新 `docs/CHANGELOG.md` 加入本修復條目
- [ ] `[OPS]` 部署後端 + `php8.2-fpm reload` + OPcache 清除
