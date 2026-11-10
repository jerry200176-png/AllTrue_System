---
name: Fix Trial Capacity Block
overview: 試聽（trial）課型被老師容量守衛錯誤攔截，導致無法將試聽學生排入已有正式學生的時段。本 PRD 修正容量守衛邏輯，允許試聽學生以「加入現有課堂」的語意繞過容量上限，符合補習班主任日常操作方式。
todos:
  - id: preflight-guard
    content: "[PRE-FLIGHT] 讀取確認：(1) ScheduleGuardService 的老師容量判斷邏輯（兩個 branch 的觸發條件與傳入參數）；(2) classCapacityMap 中 trial 對應值；(3) ScheduleGuardrailsTest.php 現有測試案例清單（確認無 trial 相關測試，避免修改後衝突）。記錄所有行號後才進入下一步。"
    status: completed
  - id: backend-fix-trial-guard
    content: "[BACKEND] 修改 backend/app/Services/ScheduleGuardService.php 中的老師容量衝突判斷邏輯：當排課類型為 trial 時，允許試聽學生加入已有任意學生數的時段（不受 trial 容量限制與既有課型容量限制的雙重攔截）。修改範圍僅限單一容量判斷方法，不影響其他課型。"
    status: completed
  - id: uiux-na
    content: "[UI/UX 精緻化] 本次不適用，原因：純後端邏輯修正，無前端 UI 元件變更。錯誤對話框（調課失敗）因修正後不再出現，屬行為變更而非視覺設計工作。"
    status: completed
  - id: test-trial-guard
    content: "[TEST] 在 backend/tests/Feature/ScheduleGuardrailsTest.php 新增測試案例：(1) test_trial_can_join_slot_with_existing_one_on_one_student：老師時段已有一對一學生，試聽學生調課至同時段 → 應允許（200/201），無 teacher_capacity 衝突；(2) test_two_trial_students_same_slot_are_blocked：同一時段嘗試排入第 2 位試聽學生（老師已有 1 位試聽學生）→ 仍應受容量守衛攔截；執行 ./vendor/bin/phpunit --filter ScheduleGuardrailsTest 確認全部通過。"
    status: completed
  - id: regression-guard
    content: "[TEST/REGRESSION] 執行 ./vendor/bin/phpunit --filter ScheduleGuardrailsTest 確認現有 4 個測試案例仍全部通過（一對二第三位學生被擋、一對一阻擋一對二新增、教室容量、調課目標被擋）。"
    status: completed
  - id: security-guard
    content: "[資安] 確認：(1) trial 試聽仍受現有 role guard（director/admin/super_admin + campus 隔離）保護；(2) 修改後試聽學生不會繞過同一學生重複排課的防呆機制（雙重排課 self-conflict 仍有效）；(3) 此變更不涉及 PII 或稽核 log 格式。"
    status: completed
  - id: code-review-guard
    content: "[REVIEW] 最終審查：(1) 確認試聽豁免邏輯只對 trial 課型生效，不影響 one_on_one/one_on_two/one_on_three/tutoring；(2) 確認試聽仍無法重複排入同一個已有試聽學生的時段（第二位試聽仍被擋）；(3) ReadLints 修改過的 .php 檔案。"
    status: completed
  - id: docs-changelog-guard
    content: "[DOCS] 更新 docs/CHANGELOG.md，新增條目（日期 2026-04-20）：修正試聽課型容量守衛 bug，允許試聽學生加入已有正式學生的老師時段；說明觸發場景與修正邏輯。"
    status: completed
  - id: deploy-guard
    content: "[部署] 後端：git add -A && git commit && git push（無 migration）；驗收：重新調課彭宥勛 4/14 18:00–20:00 確認不再出現「調課失敗：老師此時段已有 1 位學生，試聽 上限為 1 位學生」；確認余潔原課堂不受影響。"
    status: completed
  - id: uiux-signoff-na
    content: "[UI/UX sign-off] 本次不適用，原因：無前端視覺設計工作，UI/UX Designer 不需要 sign-off。"
    status: completed
  - id: pm-signoff
    content: "[PM sign-off] 確認 DoD 全部打勾：試聽可加入既有時段、既有容量邏輯未迴歸、CHANGELOG 更新。"
    status: completed
isProject: false
---

# PRD — 試聽學生無法加入既有上課時段（容量守衛 Bug 修正）

## 1. 文件資訊

| 欄位 | 內容 |
|------|------|
| 功能名稱 | 試聽（trial）課型老師容量守衛修正 |
| 版本 / 日期 | v1.0 / 2026-04-20 |
| 狀態 | Draft |
| 目標角色 | 主任（負責安排試聽學生） |

---

## 2. 目標與業務背景

### 痛點

主任在安排試聽學生時，標準操作是「讓試聽學生坐進某位正式學生的課堂旁聽」。  
例如：余潔每週一 18:00–20:00 上物理，彭宥勛申請試聽，主任將彭宥勛的試聽課調到同一時段，希望兩人一起上課。

然而系統彈出：**「調課失敗：老師此時段已有 1 位學生，試聽 上限為 1 位學生。」**

**這是系統 Bug**，原因是容量守衛把試聽課的上限也設為 1（與一對一相同），且計算「現有學生數」時已把余潔算進去，導致試聽學生一定無法加入任何已有學生的時段 — 與主任的業務意圖完全相反。

### 業務價值

- 主任可以用「試聽」課型將試聽生排入現有課堂，不必手動跳過系統限制
- 排課失敗的誤報消除後，主任操作信心提升，不擔心系統阻擋正常業務
- 試聽轉正式學生的轉換率因流程順暢而提升

### 成功指標 (KPI)

- 試聽調課成功率：排入已有正式學生的時段時，系統回覆成功率 = 100%（不再出現誤攔截）
- 既有正式課型容量守衛回歸：一對一 / 一對二 / 輔導等課型的容量限制與修正前行為完全一致

---

## 3. 範圍

### In Scope

- 修正容量守衛邏輯：試聽課型可加入老師已有其他學生的時段，不受試聽課型自身容量上限（1）的阻擋
- 修正第二個攔截點：試聽加入時，現有課型（如一對一）已滿的警告亦不觸發
- 新增測試案例覆蓋試聽加入既有時段的正常與邊界場景

### Out of Scope

- 試聽課型的計費邏輯不涉及本次修正
- 一對一、一對二、一對三、輔導等正式課型的容量上限不改變
- 前端頁面無視覺修改（錯誤對話框因修正後不再出現，屬行為變更）
- 不允許同一時段放入 2 位試聽學生（此限制保留）

---

## 4. RACI

| 角色 | 代表 | R/A/C/I |
|------|------|---------|
| PM | 產品負責人 | A（負責簽核） |
| CTO / 工程 Lead | 後端工程師 | R（實作） |
| UI/UX Designer | 設計師 | I（無前端設計工作） |
| QA | 測試工程師 | R（驗收） |
| 資安 | 資安工程師 | C（確認試聽豁免不產生安全漏洞） |
| IT / Ops | 運維人員 | I（部署通知） |

---

## 5. User Stories

### US-001 — 試聽學生順利加入既有時段

> **As a** 主任，**I want** 將試聽學生的課程調到某老師已有一位正式學生的時段，**so that** 試聽生可以和正式學生一起上課，不需要繞過任何系統限制。

Acceptance Criteria：
- [ ] 老師時段已有 1 位一對一學生時，試聽學生調課至同一時段應成功（HTTP 200），不出現任何衝突訊息
- [ ] 調課成功後，原正式學生的課堂資料不受影響
- [ ] 若嘗試在同一時段加入第 2 位試聽學生，系統仍應攔截

### US-002 — 現有容量守衛不迴歸

> **As a** QA，**I want** 確認正式課型的容量守衛未受試聽修正影響，**so that** 一對一阻擋第二位學生、一對二阻擋第三位學生等邏輯與修正前一致。

Acceptance Criteria：
- [ ] 現有 ScheduleGuardrailsTest 全部測試案例仍通過

---

## 5b. UI/UX 精緻化需求

本次修正為純後端邏輯修正，**無前端 UI 元件新增或修改**。

唯一的使用者體驗變化：
- **修正前**：點擊「確認調課」後，彈出橘色錯誤對話框「調課失敗：老師此時段已有 1 位學生，試聽 上限為 1 位學生。」
- **修正後**：點擊「確認調課」後，調課成功，顯示現有成功提示（行為與調課其他正常時段一致）

無需 UI/UX Designer 介入；現有成功狀態的視覺設計已符合規範。

---

## 6. 功能需求 (FR)

**FR-001 — 試聽課型可加入已有學生的老師時段**

系統應允許試聽（trial）課型的排課操作在老師時段內已有其他學生時成功執行，不觸發老師容量衝突（`teacher_capacity`）錯誤，前提為：加入後該時段的試聽學生數不超過 1 位。

**FR-002 — 一位試聽上限仍然有效**

當一個老師時段已有 1 位試聽學生時，再嘗試加入第 2 位試聽學生，系統仍應返回 `teacher_capacity` 衝突，拒絕排課。

**FR-003 — 正式課型容量守衛完全不受影響**

`one_on_one`、`one_on_two`、`one_on_three`、`tutoring` 等課型的容量上限與現有邏輯完全一致（回歸保護）。

---

## 7. 非功能需求 (NFR)

**NFR-001 — 效能**

本次修正為容量守衛判斷邏輯中的早期返回，不增加任何資料庫查詢。API 回應時間不受影響（< 500ms 目標不變）。

**NFR-002 — 降級策略**

若 git revert 後重新部署，行為立即回到修正前（試聽仍被攔截）。無資料遷移、無副作用。

---

## 8. 技術方向（給 CTO）

### 受影響元件

| 層 | 位置 | 修改說明 |
|----|------|---------|
| 後端服務 | `backend/app/Services/ScheduleGuardService.php` | 老師容量衝突判斷邏輯中，對 trial 課型新增豁免處理，允許其加入既有時段 |

### 架構選擇理由

- **試聽語意 ≠ 正式選課**：試聽是主任主動安排的一次性旁聽行為，容量守衛是為正式重複排課設計的；兩種語意應有不同規則。
- **最小範圍修改**：僅修改容量衝突判斷邏輯中的 trial 分支，不觸碰 classCapacityMap（其他課型不受影響），降低迴歸風險。
- **無 migration**：邏輯修正，不涉及資料表結構變更。

### 子任務派發

- `[FEATURE]` → 後端容量守衛邏輯修正
- `[TEST]` → 新增 trial 相關測試案例 + 回歸測試
- `[REVIEW]` → 確認豁免邏輯的邊界條件正確
- `[DOCS]` → 更新 CHANGELOG.md

---

## 9. 資安與存取控制

**存取控制**：試聽排課操作沿用現有 `director/admin/super_admin + campus` 隔離保護，本次修正不新增或修改任何 middleware。

**PII**：無新增 PII 處理。

**稽核 log**：調課操作（`PUT /api/v1/schedules/{id}`）現有 log 機制不變，試聽成功調課會被正常記錄。

**STRIDE 快評**：

| 威脅 | 評估 |
|------|------|
| Spoofing | 低：沿用現有身份驗證 |
| Tampering | 低：試聽豁免僅跳過容量判斷，不修改任何資料儲存邏輯 |
| Repudiation | 低：調課操作有 log，試聽身份由 class_type 欄位記錄 |
| Information Disclosure | 低：無新增資料暴露 |
| Denial of Service | 低：早期返回比原本邏輯更輕量 |
| Elevation of Privilege | 低：試聽豁免只對 trial 課型生效，不影響其他課型或角色權限 |

---

## 10. QA 驗收標準與測試計畫

### FR-001 — 試聽加入既有時段

| 路徑 | 測試案例 | 預期結果 |
|------|---------|---------|
| Happy Path | 老師時段已有 1 位一對一學生，試聽學生調課至同時段 | HTTP 200，無 `teacher_capacity` 衝突，原學生課堂不受影響 |
| Happy Path | 老師空時段，直接新增試聽課 | HTTP 201，正常建立 |
| Edge Case | 老師時段已有 2 位一對二學生（滿員），試聽學生調課至同時段 | HTTP 200，試聽仍允許加入（試聽豁免規則不受既有課型容量限制） |

### FR-002 — 一位試聽上限

| 路徑 | 測試案例 | 預期結果 |
|------|---------|---------|
| Error Case | 老師時段已有 1 位試聽學生，嘗試再加入 1 位試聽學生 | HTTP 409，`conflicts[0].type = teacher_capacity` |

### FR-003 — 正式課型回歸

| 路徑 | 測試案例 | 預期結果 |
|------|---------|---------|
| 回歸 | 一對二時段已有 2 名學生，嘗試新增第 3 位 | HTTP 409（現有測試 test_one_on_two_third_student_is_blocked 仍通過） |
| 回歸 | 一對一時段嘗試新增一對二學生 | HTTP 409（現有測試 test_existing_one_on_one_blocks_new_one_on_two 仍通過） |
| 回歸 | 調課目標時段老師已滿員 | HTTP 409（現有測試 test_schedule_reschedule_target_is_blocked 仍通過） |

### UI/UX 驗收清單

本次為純後端修正，無前端元件變更。驗收項目如下（行為驗收代替視覺驗收）：

- [ ] 調課至已有學生的時段後，不出現「調課失敗」錯誤對話框
- [ ] 調課成功後，現有成功狀態提示正常顯示（視覺與其他成功調課一致）
- [ ] 余潔原時段課堂資料（時段、老師、課型）不受彭宥勛加入影響

---

## 11. 上線與維運

### 部署步驟

1. 後端無 migration，直接部署 Laravel（`git push`）
2. 驗收：重新執行彭宥勛 4/14 18:00–20:00 調課，確認成功
3. 確認余潔原課堂資料不受影響

### 監控

無需新增監控項目。現有調課操作 log 已涵蓋。

### 回滾方案

`git revert <commit>` 後重新部署，行為立即回到修正前，無資料副作用。

---

## 12. 里程碑與優先級

| 優先級 | 功能項目 | 預估工期 | 執行 Agent |
|--------|---------|---------|-----------|
| P0 | FR-001/002 試聽容量守衛修正 | 0.5h | `[FEATURE]` |
| P0 | FR-003 回歸測試 + 新增試聽測試案例 | 0.5h | `[TEST]` |
| P1 | Code Review + 資安確認 | 0.25h | `[REVIEW]` |
| P1 | CHANGELOG 更新 + 部署 | 0.25h | `[DOCS]` / IT |

---

## 13. 風險、假設、開放問題

### 風險

**RISK-001 — 試聽豁免過於寬鬆 ★☆☆ 低**

| 項目 | 內容 |
|------|------|
| 可能性 | 低（試聽課型在業務上就是一次性旁聽，不應受容量守衛限制） |
| 業界參照 | 試聽/體驗課（trial class）在補習班系統設計慣例中屬於「加入既有班」語意，不獨佔師資時段 |
| 具體緩解 | 保留「同一時段僅允許 1 位試聽」的上限（FR-002），避免無限堆疊試聽學生 |
| 殘留風險 | 極低 |

### 假設

**假設 A — 試聽課型永遠是加入既有課堂，不會單獨成班**
- 依據：業務場景中，主任不會為試聽單獨開班；試聽本質是「讓學生看看既有課堂」
- 若假設錯誤：需重新評估是否保留部分容量守衛（但目前業務不需要）

**假設 B — 每個老師時段最多允許 1 位試聽學生**
- 依據：與現有 `classCapacityMap['trial'] = 1` 的設定一致；業務上不需要多個試聽學生同時旁聽

### 開放問題

無。本 bug 成因明確，修正範圍已確認，不需要額外確認。

---

## 14. Definition of Done

- [ ] FR-001：試聽學生成功調課至已有正式學生的時段，無衝突訊息
- [ ] FR-002：同一時段第 2 位試聽學生仍被攔截
- [ ] FR-003：`ScheduleGuardrailsTest` 現有 4 個測試案例全部通過（回歸無誤）
- [ ] 新增試聽相關測試案例通過（trial 加入既有時段、第 2 位 trial 被攔）
- [ ] 資安 STRIDE 快評確認無阻擋風險
- [ ] `[REVIEW]` 確認豁免邏輯只對 trial 課型生效
- [ ] ReadLints 修改過的 .php 檔案零 linter 錯誤
- [ ] `docs/CHANGELOG.md` 已更新
- [ ] 實機驗收：彭宥勛 4/14 18:00–20:00 調課成功
- [ ] PM sign-off
