---
name: Fix Student Notes Clear Bug
overview: 學生管理「備註」欄位清除後仍顯示舊值的 Bug 修正：根本原因為 Laravel PUT 成功時提前 return，Supabase 資料未同步更新，導致 loadStudents 走 Supabase fallback 時讀回舊值。
todos:
  - id: preflight-notes
    content: "[PRE-FLIGHT] 讀取確認：(1) StudentsList.vue submitStudent 編輯路徑（L1530–1561）的 res.ok 分支與 Supabase fallback 位置（確認 L1562 在早期 return 之後，永遠不執行）；(2) StudentController::update L204 的 isset 判斷；(3) Supabase students 表是否有 notes 欄位（執行 Supabase SQL 或 schema 查詢確認）。記錄行號後進入下一步。"
    status: pending
  - id: frontend-fix-notes
    content: "[FEATURE-FE] 修改 frontend/src/pages/StudentsList.vue submitStudent 的 res.ok 分支（約 L1546–1558）：在 closeStudentModal() 前加入 supabase.from('students').update(payload).eq('id', editingStudentId.value).then(() => {})，確保 Laravel 成功後 Supabase 同步更新（fire-and-forget，不 await）。"
    status: pending
  - id: backend-fix-notes
    content: "[FEATURE-BE] 修改 backend/app/Http/Controllers/StudentController.php update 方法（約 L204）：將 isset($input['notes']) 改為 array_key_exists('notes', $input)，賦值改為 $student->notes = $input['notes'] ?? ''，確保 notes: null 也能清空 DB 欄位。"
    status: pending
  - id: uiux-na-notes
    content: "[UI/UX 精緻化] 本次不適用，原因：純資料同步邏輯修正，無前端 UI 元件新增或視覺變更。備註欄位清除後顯示正確屬行為修正而非設計工作。"
    status: pending
  - id: test-notes
    content: "[TEST] 手動驗收：(1) 以主任帳號開啟學生管理，找一位有備註的學生，清空備註欄位，按儲存；(2) 確認學生列表備註欄位立即顯示空白；(3) 重新整理頁面確認備註仍為空；(4) 重新開啟該學生編輯彈窗確認備註欄位為空；(5) 另測試修改備註為新值並確認正確顯示。"
    status: pending
  - id: regression-notes
    content: "[TEST/REGRESSION] 確認其他欄位（姓名、年級、電話、家長資訊）的編輯儲存邏輯未受影響；確認備後端 array_key_exists 修改不影響 PUT body 不含 notes 鍵時的行為（notes 不被覆寫）。"
    status: pending
  - id: security-notes
    content: "[資安] 確認：(1) Supabase update 使用現有 auth token，不新增暴露面；(2) backend array_key_exists 修改不影響現有 campus 隔離保護；(3) 此變更不涉及新增 PII 欄位或稽核 log 格式。"
    status: pending
  - id: code-review-notes
    content: "[REVIEW] 最終審查：(1) 確認 Supabase sync 為 fire-and-forget，不阻塞 UI 關閉流程；(2) 確認後端 array_key_exists 邏輯正確（有 notes 鍵且值非 null → 更新；有 notes 鍵且值為 null → 清空；無 notes 鍵 → 不更新）；(3) ReadLints 修改過的 .vue 與 .php 檔案。"
    status: pending
  - id: docs-changelog-notes
    content: "[DOCS] 更新 docs/CHANGELOG.md，新增條目（日期 2026-04-20）：修正學生管理備註欄位清除無效 bug（雙資料源同步缺口），確保 Laravel PUT 成功後 Supabase 同步更新；說明觸發場景與修正邏輯。"
    status: pending
  - id: deploy-notes
    content: "[部署] 前端 + 後端：git add -A && git commit && git push；驗收：實機確認備註清除後顯示正確；確認其他欄位儲存邏輯不受影響。"
    status: pending
  - id: pm-signoff-notes
    content: "[PM sign-off] 確認 DoD 全部打勾：備註清除後顯示為空（含重新整理）、其他欄位邏輯未迴歸、CHANGELOG 更新。"
    status: pending
isProject: false
---

# PRD — 學生備註清除後仍顯示舊值（雙資料源同步 Bug 修正）

## 1. 文件資訊

| 欄位 | 內容 |
|------|------|
| 功能名稱 | 學生管理備註欄位清除無效 Bug 修正 |
| 版本 / 日期 | v1.0 / 2026-04-20 |
| 狀態 | Draft |
| 目標角色 | 主任（負責管理學生資料） |

---

## 2. 目標與業務背景

### 痛點

主任在【學生管理】中修改學生備註後，若清除備註欄位並儲存，畫面仍顯示原有備註內容，無法清空。

**這是系統 Bug**，原因是前端的雙資料源架構（Laravel 為主、Supabase 為備援）存在同步缺口：編輯儲存時，Laravel PUT 成功後程式提前 `return`，Supabase 的學生記錄未隨之更新。若後續 `loadStudents()` 走 Supabase fallback 路徑（例如 token 短暫失效），就會讀回 Supabase 中的舊備註值，顯示原有內容。

### 業務價值

- 主任可信任備註欄位的清除與修改操作，不需要反覆確認
- 消除資料顯示不一致帶來的困惑與操作信心問題

### 成功指標 (KPI)

- 備註清除後儲存，下一次重新整理或開啟編輯彈窗均顯示空白
- 備註修改後儲存，顯示值與儲存值完全一致

---

## 3. 範圍

### In Scope

- 修正 `submitStudent` 編輯路徑：Laravel PUT 成功時，同步更新 Supabase 備註欄位
- 補強後端：`StudentController::update` 中，若前端送來 `notes: null`，應視為清空（轉為 `""`），避免 `isset(null) = false` 造成更新被跳過
- 驗證 `loadStudents` Supabase fallback 路徑讀回資料後備註欄位正確為空

### Out of Scope

- 其他欄位（姓名、電話等）的雙資料源同步問題（另立 ticket 追蹤）
- Supabase schema 結構調整（不新增 migration）
- 前端 UI 視覺設計修改

---

## 4. RACI

| 角色 | 代表 | R/A/C/I |
|------|------|---------|
| PM | 產品負責人 | A（負責簽核） |
| CTO / 工程 Lead | 前端 + 後端工程師 | R（實作） |
| UI/UX Designer | 設計師 | I（無前端設計工作） |
| QA | 測試工程師 | R（驗收） |
| 資安 | 資安工程師 | I（無安全影響） |
| IT / Ops | 運維人員 | I（部署通知） |

---

## 5. User Stories

### US-001 — 備註清除後儲存即時生效

> **As a** 主任，**I want** 清空備註欄位後按儲存，**so that** 學生備註顯示為空，不再顯示舊值。

Acceptance Criteria：
- [ ] 清除備註欄位並儲存後，學生列表立即顯示備註為空
- [ ] 重新整理頁面後，備註仍為空（持久化正確）
- [ ] 重新開啟同一學生的編輯彈窗，備註欄位顯示為空

### US-002 — 備註修改後儲存即時生效

> **As a** 主任，**I want** 將備註從舊值改為新值後儲存，**so that** 顯示的是最新備註，不出現舊值回顯。

Acceptance Criteria：
- [ ] 備註修改儲存後，學生列表顯示新備註
- [ ] 重新整理後仍顯示新備註（不回退）

---

## 5b. UI/UX 精緻化需求

本次為純邏輯 Bug 修正，**無前端 UI 元件新增或修改**。

唯一的使用者體驗變化：
- **修正前**：清除備註後儲存，畫面仍顯示舊備註（尤其在 token 短暫失效或 Supabase fallback 觸發時必現）
- **修正後**：清除備註後儲存，畫面立即且持久顯示空白備註

無需 UI/UX Designer 介入。

---

## 6. 功能需求 (FR)

**FR-001 — 前端：Laravel PUT 成功後同步 Supabase**

在 `submitStudent` 的編輯路徑中，當 Laravel PUT 回應 `res.ok` 時，應在 `closeStudentModal()` 前（或至少在 `return` 前），對 Supabase 執行 `update(payload)` 以保持兩個資料源一致。確保即使 `loadStudents()` 走 Supabase fallback，也能讀回正確（已清空）的備註值。

**FR-002 — 後端：`notes: null` 明確清空**

`StudentController::update` 中，將 `notes` 的判斷從 `isset($input['notes'])` 改為 `array_key_exists('notes', $input)`，並在賦值時對 `null` 做明確處理：
```php
if (array_key_exists('notes', $input)) {
    $student->notes = $input['notes'] ?? '';
}
```
這確保前端送來 `"notes": null` 時（例如 JSON 序列化邊界情況），後端仍將備註清空，不因 `isset(null) = false` 而靜默跳過更新。

---

## 7. 非功能需求 (NFR)

**NFR-001 — 效能**

Supabase 同步為 fire-and-forget（不 block UI 關閉），不影響使用者感知儲存速度。

**NFR-002 — 降級策略**

`git revert` 後重新部署即可回滾，無資料遷移、無副作用。

---

## 8. 技術方向（給 CTO）

### 根本原因

| # | 位置 | 問題 |
|---|------|------|
| 1 | `StudentsList.vue` `submitStudent`（~L1546–1557） | Laravel PUT 成功 → 早期 `return`，Supabase `update(payload)`（~L1562）永遠不執行 |
| 2 | `StudentController.php` `update`（~L204） | `isset($input['notes'])` 對 `null` 回傳 `false`，`null` 值不更新 DB → 靜默跳過清空請求 |

### 修改位置

| 層 | 位置 | 修改說明 |
|----|------|---------|
| 前端 | `frontend/src/pages/StudentsList.vue` L1546–1558 | 在 `res.ok` 分支內，`return` 前加入 Supabase `update(payload)` |
| 後端 | `backend/app/Http/Controllers/StudentController.php` L204 | `isset` → `array_key_exists`，賦值時 `?? ''` 處理 null |

### 修改草稿（前端）

```javascript
// 修改前（L1546–1557）
if (res.ok) {
  if (payload.rfid) { /* bind card */ }
  closeStudentModal();
  loadStudents();
  loadAllStudentCourses();
  return;
}

// 修改後
if (res.ok) {
  if (payload.rfid) { /* bind card */ }
  // Sync to Supabase so fallback reads stay consistent
  supabase.from('students').update(payload).eq('id', editingStudentId.value).then(() => {});
  closeStudentModal();
  loadStudents();
  loadAllStudentCourses();
  return;
}
```

### 修改草稿（後端）

```php
// 修改前
if (isset($input['notes'])) $student->notes = $input['notes'];

// 修改後
if (array_key_exists('notes', $input)) $student->notes = $input['notes'] ?? '';
```

### 子任務派發

- `[FEATURE-FE]` → 前端 Supabase 同步補丁
- `[FEATURE-BE]` → 後端 `array_key_exists` + null 防護
- `[TEST]` → 手動驗收 + 回歸確認
- `[DOCS]` → CHANGELOG 更新

---

## 9. 資安與存取控制

**存取控制**：學生資料更新沿用現有 `director/admin/super_admin + campus` 隔離保護，本次修正不新增或修改任何 middleware。

**PII**：備註欄位屬 Student PII；Supabase `update(payload)` 已在現有安全邊界內執行（Supabase auth token 沿用），無新增暴露面。

**STRIDE 快評**：

| 威脅 | 評估 |
|------|------|
| Spoofing | 低：沿用現有身份驗證 |
| Tampering | 低：僅修正同步邏輯，不改變授權模型 |
| Repudiation | 低：Laravel `update` log 不變 |
| Information Disclosure | 低：無新增資料暴露 |
| Denial of Service | 低：Supabase sync 為非同步，不阻塞主流程 |
| Elevation of Privilege | 低：campus 隔離不受影響 |

---

## 10. QA 驗收標準與測試計畫

### FR-001/002 — 備註清除

| 路徑 | 測試案例 | 預期結果 |
|------|---------|---------|
| Happy Path | 備註有值 → 清空 → 儲存 | 列表立即顯示備註空白；重新整理後仍空白 |
| Happy Path | 備註有值 → 改新值 → 儲存 | 列表顯示新備註；重新整理後仍顯示新備註 |
| Edge Case | 備註從未填寫 → 直接儲存 | 列表備註欄位空白，無錯誤 |
| Edge Case | 儲存後立即重新開啟編輯彈窗 | 備註欄位顯示正確（空白或新值），不回顯舊值 |

### 後端 null 防護

| 路徑 | 測試案例 | 預期結果 |
|------|---------|---------|
| Edge Case | PUT body 送 `{"notes": null}` | DB `notes` 欄位更新為 `""` |
| Edge Case | PUT body 不含 `notes` 鍵 | DB `notes` 欄位不變（`array_key_exists` 為 false） |

### UI/UX 驗收清單

- [ ] 清除備註儲存後，列表中「備註」欄位顯示空白或不顯示
- [ ] 重新整理頁面後，備註欄位確認為空
- [ ] 重新開啟同一學生編輯彈窗，備註欄位確認為空
- [ ] 其他欄位（姓名、電話等）的儲存邏輯不受影響

---

## 11. 上線與維運

### 部署步驟

1. 後端無 migration，直接部署 Laravel（`git push`）
2. 前端重新 build 並部署（`git push` 觸發 CI/CD）
3. 驗收：實際以主任帳號開啟學生管理，找一位有備註的學生清空備註後儲存，確認顯示正確

### 監控

無需新增監控項目。Supabase sync 失敗為 non-blocking，不影響主功能。

### 回滾方案

`git revert <commit>` 後重新部署，行為立即回到修正前，無資料副作用。

---

## 12. 里程碑與優先級

| 優先級 | 功能項目 | 預估工期 | 執行 Agent |
|--------|---------|---------|-----------|
| P0 | FR-001 前端 Supabase 同步補丁 | 0.25h | `[FEATURE-FE]` |
| P0 | FR-002 後端 null 防護 | 0.25h | `[FEATURE-BE]` |
| P1 | 手動驗收 | 0.25h | `[TEST]` |
| P1 | CHANGELOG 更新 + 部署 | 0.25h | `[DOCS]` / IT |

---

## 13. 風險、假設、開放問題

### 風險

**RISK-001 — Supabase sync 靜默失敗 ★☆☆ 低**

| 項目 | 內容 |
|------|------|
| 可能性 | 低（Supabase update 錯誤不影響 Laravel 主資料，僅影響 fallback 路徑） |
| 業界參照 | 雙寫（dual-write）架構下，主資料源（Laravel）成功即視為操作成功；副資料源（Supabase）同步失敗屬可接受的最終一致性延遲 |
| 具體緩解 | Supabase sync 為 fire-and-forget（不 await），不阻塞 UI；長期可考慮加入 catch 寫入 console.warn 以利偵錯 |
| 殘留風險 | 極低；若 Supabase sync 失敗，下一次 loadStudents 走 Laravel 主路徑時會修正顯示 |

### 假設

**假設 A — Supabase `students` 表有 `notes` 欄位**
- 若 Supabase 無此欄位：update 會靜默忽略 `notes` 鍵，fallback 讀取時 `notes` 為 `undefined`，前端 `|| ''` fallback 顯示為空 — 清除操作等效正確，但備註填值時 fallback 仍顯示空（另一 bug）。本 PRD 的修正對此情況無害。

**假設 B — 前端 textarea `v-model="studentForm.notes"` 清除後正確反映為空字串 `""`**
- 已確認：Vue 3 template 中 ref 自動解包，清除 textarea 後 `studentForm.value.notes = ""`，payload 中 `notes: ""` 正確送出。

### 開放問題

無。bug 成因明確，修正範圍已確認。

---

## 14. Definition of Done

- [ ] FR-001：清除備註後儲存，Supabase 同步更新為空值
- [ ] FR-002：後端 PUT 接受 `notes: null` 並清空 DB 欄位
- [ ] 手動驗收：實際操作確認備註清除後顯示為空（含重新整理）
- [ ] 其他欄位儲存邏輯未受影響（回歸）
- [ ] ReadLints 修改過的 `.vue` 與 `.php` 檔案零 linter 錯誤
- [ ] `docs/CHANGELOG.md` 已更新
- [ ] PM sign-off
