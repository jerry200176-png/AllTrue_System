# Bug Fix Plan：課表重複顯示 + 0元課程無法確認繳費

---

## 0. 根因確認（Root Cause）

### BUG-A：王柏軒週一18-20課表重複出現

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P2（有 workaround：主任可手動結案，但視覺混亂） |
| 根因類型 | 資料狀態 + 前端渲染邏輯 |
| 根因摘要 | 最可能：王柏軒有**兩筆 Stop=0 的 StudentClass**，兩者都設有週一18:00-20:00時段（如舊批次未結案 + 新批次），前端 `courses.value` 中出現兩筆，分別渲染兩個基底格；次要可能：`schedules` 中有一筆 `status='scheduled'` 補課/調課行與基底格時間重疊（`student_course_id` 不同），跳過 `scheduledExcStartSet` 過濾 |
| 錯誤行為 | 同一學生、同一週一18-20，課表顯示兩張課程卡片 |
| 預期行為 | 同一學生同時段最多一張卡片（若真有兩筆獨立課程，應清晰標示） |
| 影響範圍 | SmartCalendar.vue 週視圖、所有分校主任/老師的課表顯示 |
| B1 偵查來源 | 本計畫整合 B1 內容 |

**三個候選根因（需驗證哪個命中）**：

| RC | 描述 | 驗證方式 |
|---|---|---|
| RC-1 | 兩筆 Stop=0 的 StudentClass 都有週一18:00時段（最可能） | `GET /api/v1/student-classes?student_name=王柏軒` 查有幾筆 Stop=0 |
| RC-2 | 一筆 StudentClass 基底格 + 一筆 `schedules` `status='scheduled'` 不同 `student_course_id` 的補課行 | 查 `schedules` WHERE student_id=王柏軒 AND day_of_week=1 AND status='scheduled' |
| RC-3 | `days_of_week` 含重複值（如 `[1,1]`）導致同課程渲染兩次 | 看 API 回傳的 course.days_of_week |

---

### BUG-B：輔導課（或 Charge=0）課程無法確認繳費

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P2（輔導課/免費課/試聽課永遠無法標記已繳費） |
| 根因類型 | 前端 + 後端三層金額驗證全封鎖 0 |
| 根因摘要 | `PaymentEntryModal.vue` HTML `min="1"` + JS `amount <= 0` 雙層擋住；`PaymentReportController::directorRecord` 後端 `min:1` validation 也擋住。Charge=0 的課程（輔導、試聽、家庭優惠等）的主任無論如何都無法點按「確認核帳」 |
| 錯誤行為 | 點擊繳費按鈕 → modal 金額自動填 0 → 無法送出 |
| 預期行為 | Charge=0 課程視為「免費已結算」，主任可以 $0 確認核帳，Invoice 標為 paid |
| 影響範圍 | 所有 Charge=0 的課程（輔導課 Rate=0、試聽課、折扣優惠課）；DirectorDashboard 繳費提醒持續顯示這些課程永遠無法被清除 |
| B1 偵查來源 | 本計畫整合 B1 內容 |

**業界參考**（WebSearch）：WooCommerce/Stripe 均支援 0 元訂單/invoice，視為「已全額折扣」；Tutorbase 對免費課程仍會建立 invoice 記錄，只是金額為 0。

---

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 課表重複 + 0元繳費雙 Bug Fix |
| 版本 | v1.0 |
| 狀態 | B1 完成，等 B2 執行批准 |
| 嚴重度 | BUG-A P2 / BUG-B P2 |
| 目標角色 | 主任（director）、老師（teacher） |
| 關聯 Bug | BUG-A：王柏軒週一18-20重複；BUG-B：輔導/0元課繳費 |

---

## 2. 業務背景與影響

**BUG-A**：
- 課表重複顯示造成主任和老師視覺混亂，以為排了兩個班次
- 補課空檔計算（capacity）可能因重複計數誤判為滿員
- 修復後預期行為：同一學生同一時段在課表上只出現一次（若確有兩筆合法課程則清晰區分）

**BUG-B**：
- 輔導課（tutoring）常設 Rate=0，月結制輔導課 Charge=0；試聽課免費
- 主任無法為這些課程確認繳費 → DirectorDashboard 繳費提醒永遠有這些課 → 提醒列表被污染
- 修復後預期行為：Charge=0 課程可以以 $0 確認繳費，Invoice 標為 paid，從提醒清單消失

---

## 3. 範圍

### In Scope（BUG-A）
- SmartCalendar.vue：識別 RC-1/RC-2/RC-3 並修復
- 若 RC-1（兩筆 Stop=0 StudentClass）：在課程建立時加入重複時段防護警告（前端）
- 提供主任快速結案重複課程的途徑（已有 Stop 功能，非本次新增）

### In Scope（BUG-B）
- `PaymentEntryModal.vue`：移除 0 金額封鎖，顯示「免費課程，金額為 $0」提示
- `PaymentReportController::directorRecord`：`amount` 改為 `min:0`
- 相關前端 `isCourseSettled` 邏輯確認 Charge=0 + Paid=1 可正確標記為 paid

### Out of Scope
- `AlertController::tuition` 邏輯（不在本次範圍，避免動到保護文件）
- 補課空檔演算法（CourseManagement.vue fetchMakeupSlots）
- StudentClass 批次資料清理（主任手動處理王柏軒的重複資料）
- Payment 金額為負數的退費情境

---

## 4. RACI

| 角色 | 負責人 |
|---|---|
| 後端 + 前端修復 | `[DEV]` AI Agent |
| PHPUnit Regression | `[TEST]` AI Agent |
| Code Review | `[REVIEW]` AI Agent |
| 文件更新 | `[DOCS]` AI Agent |
| 部署 health check | `[OPS]` AI Agent |
| 最終確認 | CEO（使用者）→ I only |

## 4b. Dependencies

- 前置：無（不依賴未 merge 的 PR）
- BUG-A 修復前需確認 RC 類型（先看 API 回傳資料）
- 環境：WSL2 本地開發；CI = GitHub Actions

---

## 5. Acceptance Criteria

### AC-001：BUG-A — 課表不重複顯示
- AC-001-a：週視圖中，同一學生、同一日期、同一開始時間，最多顯示一張課程卡片（有兩筆合法獨立課程時顯示兩張，但需有不同科目/老師/ID 明顯區分）
- AC-001-b：RC-1 修復後，兩筆 Stop=0 StudentClass 同時段，舊批次顯示為停用/歷史狀態（Stop=1 或已關閉），不出現在課表

### AC-002：BUG-B — 0元課程可確認繳費
- AC-002-a：Charge=0 的課程，主任點擊繳費按鈕，modal 顯示金額 $0，可以成功送出
- AC-002-b：`POST /api/v1/payment-reports/director-record` with `amount=0` 回傳 200，Invoice.Status 更新為 `paid`，StudentClass.Paid=1
- AC-002-c：確認後課程從 DirectorDashboard 繳費提醒移除（payment_status 為 paid）

---

## 6. 功能需求（FR）

| ID | 需求 |
|---|---|
| FR-001 | `directorRecord` 驗證：`amount` 由 `min:1` 改為 `min:0`，允許 0 元確認 |
| FR-002 | `PaymentEntryModal.vue`：HTML `min="1"` 改為 `min="0"`；JS `amount <= 0` 改為 `amount < 0` |
| FR-003 | `PaymentEntryModal.vue`：當 `row.charge === 0` 顯示 hint「免費課程，金額為 $0，確認後標記為已結算」 |
| FR-004 | BUG-A RC-1：`SmartCalendar.vue` 的 `loadCourses` 中，同一 `student_id` 且 `days_of_week`/`start_time` 完全相同的多筆 Stop=0 課程，在 UI 上標注「⚠️ 時段重複」警告（不自動結案，由主任決定） |
| FR-005 | BUG-A RC-2：若 `schedules` 中有 `status='scheduled'` 的補課行，其 `student_course_id` 不在 `courses.value` 中，則該行不渲染（孤兒 schedule 防護） |
| FR-006 | 若確認 RC-1 為根因：在 `DuplicateCourseGuardTest` 或相關測試中補充「建立時段重複課程的警告」測試 |

---

## 7. 非功能需求（NFR）

BUG-B：純後端驗證邏輯修改，不影響效能。不適用效能指標。  
BUG-A：前端渲染邏輯調整，課程數量不變，不增加 API 呼叫，不適用效能指標。

---

## 8. 技術方向

### BUG-B（明確，直接修）
- `PaymentReportController::directorRecord`：`amount` validation `min:0`
- `PaymentEntryModal.vue`：`min="0"`、JS guard 改 `< 0`、charge=0 時顯示 hint

### BUG-A（需先確認 RC）
**RC-1（兩筆 Stop=0 StudentClass）**：
- `SmartCalendar.vue` `computedWeekSlots`（或相關計算）：檢測同一 student_id 在相同 day_of_week+start_time 有多筆 Stop=0 課程，在卡片上加 `⚠️ 重複時段` badge
- 不自動結案（副作用大），由主任手動結案

**RC-2（schedules 孤兒行）**：
- `SmartCalendar.vue` `loadCourses`：exceptions 過濾時，`status='scheduled'` 的 schedule 若 `student_course_id` 不在當前 courses 中，排除渲染

**RC-3（days_of_week 重複值）**：
- `SmartCalendar.vue` 渲染前對 days_of_week 去重（`[...new Set(course.days_of_week)]`）

> 建議按 RC-1 優先修，因最可能命中；RC-2/RC-3 防護成本低，可同批次修

---

## 8b. Decision Log

| 日期 | 決策 | 替代方案 | 選擇理由 |
|---|---|---|---|
| 2026-04-27 | BUG-B 允許 amount=0（不強制輸入） | (A) 強制主任輸入任何 >0 金額 (B) 單獨加一個「標記為免費」按鈕 | 0 元就是收款金額，不需要特別按鈕；Stripe/WooCommerce 業界標準均支援 0 元 invoice |
| 2026-04-27 | BUG-A 不自動結案重複課程 | 自動 Stop 最舊的批次 | 自動結案風險高（可能誤關有效課程），由主任決定 |
| 2026-04-27 | BUG-A RC-2/RC-3 防護同批次加入 | 只修 RC-1 | 成本低（各一行），可防止其他學生觸發相同問題 |

---

## 9. 資安與存取控制

BUG-B：允許 amount=0 不改變任何 auth/角色邊界。directorRecord 仍需 director 角色 + require_campus middleware。Payment 記錄金額 $0 是合法帳務記錄，不洩漏 PII。**不觸發 SEC 審查**。

BUG-A：純前端渲染防護，不改 API 路由。**不觸發 SEC 審查**。

---

## 10. QA 驗收

### Happy Path
- [ ] BUG-B：Charge=0 課程點繳費 → modal 顯示 $0 hint → 送出 → 成功標記 paid
- [ ] BUG-B：Charge=0 月結課程 → directorRecord API amount=0 → 200 OK
- [ ] BUG-A：兩筆 Stop=0 同時段 StudentClass 在課表卡片顯示 `⚠️ 重複時段`

### Edge Cases
- [ ] BUG-B：Charge=100 課程，主任手動改金額為 0 → 仍可送出（$0 表示免收）
- [ ] BUG-B：Charge=0 + 已有 Paid Invoice → re-confirm 不應重複建 Invoice
- [ ] BUG-A：同學生有兩筆不同科目（Math+English）在相同時間 → 不誤觸警告（不同科目可合法共存）

### Revert-proof 驗證
- [ ] git stash 後 `directorRecord` amount=0 測試應 fail
- [ ] git stash 後 `PaymentEntryModal` 測試（若有）應 fail

---

## 11. 上線與維運

| 項目 | 說明 |
|---|---|
| Migration | 無（純邏輯修改） |
| 部署 | PR merge → deploy.yml 自動（有前端改動）|
| 回滾 | `git revert <hash> --no-commit && git commit` |
| 監控 | health check 200 OK；directorRecord API smoke test |

---

## 12. 優先級

| Bug | 優先級 | 執行 Agent |
|---|---|---|
| BUG-B：0元繳費 | P0（先做，根因明確，改動小） | `[DEV]` |
| BUG-A：課表重複 | P1（後做，需確認 RC） | `[DEV]` |
| Tests | P0 | `[TEST]` |
| CHANGELOG | P1 | `[DOCS]` |
| Deploy | P1 | `[OPS]` |

---

## 13. 風險 / 假設 / 開放問題

（WebSearch 已完成）

| 風險 | 等級 | 緩解 |
|---|---|---|
| BUG-A RC 類型未確認（需看 API 回傳才能確定） | 中 | 先修 BUG-B（成本低）；BUG-A 修復前請使用者確認 RC 類型 |
| amount=0 的 Payment 記錄影響財務報表邏輯 | 低 | 現有 AccountingController 的 payments 報表 SUM 不受 0 影響 |
| 0元 Invoice 被認為是 legacy 無效資料 | 低 | 標注 Note='免費結算' 或主任可在備註說明 |

**開放問題**：
- [AI-RESOLVABLE] BUG-A RC 類型：可用 `GET /api/v1/student-classes?branch_id=X&per_page=all` 找 Stop=0 且 StudentID=王柏軒 的記錄，確認是否有兩筆同時段
- [AI-RESOLVABLE] 如果是 RC-1（兩筆 StudentClass），舊批次是哪一筆？需要主任判斷後手動結案

---

## 14. Definition of Done

- [ ] FR-001：`POST /api/v1/payment-reports/director-record` with `amount=0` → 200 OK：PHPUnit 驗收
- [ ] FR-002/FR-003：Charge=0 課程核帳 modal 正常顯示且可送出：smoke test
- [ ] BUG-A RC-2/RC-3 防護：前端 loadCourses 過濾孤兒 schedules + days_of_week 去重：code review 驗收
- [ ] Revert-proof：`git stash && 測試` 新增 case 至少 1 failure
- [ ] CI PHPUnit 全 GREEN：`gh run view <id>` → success
- [ ] CHANGELOG 更新：`git diff docs/CHANGELOG.md` 含 `2026-04-27` 新條目
- [ ] Health check：`curl -sk https://daan.lifenet.com.tw/api/v1/health` → `{"status":"ok",...}` HTTP 200

---

## Todos（Bug Fix 專用）

| 類別 | 任務 | Agent |
|---|---|---|
| 後端修復 | directorRecord `amount` min:0 | `[DEV]` |
| 前端修復 | PaymentEntryModal min=0、JS guard、hint | `[DEV]` |
| 前端修復 | SmartCalendar RC-2/RC-3 防護（孤兒 schedule 過濾 + days_of_week 去重） | `[DEV]` |
| 前端修復（RC-1）| SmartCalendar 同時段重複課程 ⚠️ 警告 badge | `[DEV]` |
| Regression Tests | directorRecord amount=0 PHPUnit | `[TEST]` |
| Revert-proof 驗證 | git stash 後測試 fail 確認 | `[TEST]` |
| Code Review | 逐條對照 FR | `[REVIEW]` |
| CHANGELOG | 更新 docs/CHANGELOG.md | `[DOCS]` |
| 部署 | PR merge + health check | `[OPS]` |
