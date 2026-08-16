# 重複功能清理計畫 A → B → C（2026-08-17）

> Founder GO：全做，順序 A→B→C。本檔為執行契約；產品行為變更（B／C）另有核准閘門。追蹤：**TD-083**（TD-082 號碼另有用途）。

## 決策摘要

全站掃描結論：**側欄產品功能幾乎無「兩頁同一件事」**；風險在（1）未掛載死碼頁、（2）金流「已繳費」多真相、（3）補請假兩路徑尾端策略不一致。

| Phase | 內容 | 風險 | 核准 |
|-------|------|------|------|
| **A** | 刪除未掛載前端死碼頁 | 低 | **已上線** #1864；計畫 #1866 |
| **B** | 收斂 `isPaid`／`isFullyPaid`（TD-073 金流子項） | 高（金流） | **分批**；`DunningService` 需另開 Founder GO |
| **C** | 補請假權威＋共用作廢 helper（TD-069）；接 TD-012 點名副作用 | 高（堂數） | 先定權威政策再改行為 |

不做：合併夜間堂數對帳與學費／銀行對帳；合併 `packages:*` 三指令；合併綁定三頁或主任總覽／收件匣。

---

## Phase A — 死碼頁清理（本 PR）

### 範圍

刪除且**不**改後端 API：

| 檔案 | 理由 |
|------|------|
| `BillingList.vue` | 側欄已由「當月學收」取代；`App.vue` 僅留註解 |
| `PayReportPage.vue` | 家長自填核帳已下線；`PaymentReport` API 仍由帳務中心使用 |
| `CoursePackagesPage.vue` | 方案建立／管理已併入 `CourseManagement` |
| `ClassesList.vue` | 舊費率表；科目改走 `SubjectSettingsPage` |
| `StudentWizard.vue` | 無任何 import／掛載 |
| `TeacherProfilePage.vue` | 科目×學段已併入 `TeachersList` |

### 驗收

- `rg`：`frontend/src` 無對上述頁的 import／`defineAsyncComponent`
- `npm run build`（frontend）綠
- CI Presubmit／PHPUnit 綠（無 PHP 變更亦須過門）
- 教職員不可感知 → `silent_ship`

### 不做

- 不刪 `BillingController`／`PaymentReportController`／`course-packages` API
- 不重寫 `design-hex-baseline.json`（刪檔不會觸發「高於 baseline」；避免單行 JSON 合併衝突）

---

## Phase B — 繳費判斷單真相（TD-073 子項）

### 權威口徑（顯示用「已足額繳清」）

對齊 `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` 與 R94：

```text
isFullyPaid = (Paid == 1) OR (charge > 0 AND paid_amount >= charge)
```

- **不可**用「任一筆收款」當已繳（會吃掉 `partial`）
- **列入催繳條件**（`mapCountModeAlert`／`monthlyAlertRow` 的 `Paid==1`）與顯示用 `payment_status` **分開**；不得混改

### 批次（各開一 PR）

| Batch | 呼叫點 | 備註 |
|-------|--------|------|
| B0 | `StudentClass` 新增 `isFullyPaid()`；`AlertController` 改呼叫 | **本 PR** — model 為 SSOT，Alert 薄委派；單元測鎖 R94 |
| B1 | `StudentClassController`、`AccountingController`、`PaymentReportController` | 顯示／帳本對齊 |
| B2 | `NotificationSyncService`、`NotificationController`、`SendTuitionReminders`、`ParentPortalController` | 通知／家長 |
| B3 | `DunningService` | **凍結**；需 Founder 書面 GO + `DIRECTOR_PAYMENT_ALERT_RULES` 對照表 |

### 驗收（每批）

- 何昀佳類回歸：足額收款 → 帳務中心與課程管理同為已繳
- `partial` 不誤變 `paid`
- 催繳列入條件 query 不變（除非 B3 明示）

---

## Phase C — 補請假＋點名副作用

### C0 — 政策裁定（寫碼前）

| 問題 | 建議預設（待 Founder 確認） |
|------|---------------------------|
| 尾端補課權威？ | `CourseLeaveCascadeService::appendTailAfterLeave()`（2026-07-26 keep-future-dates-append-tail） |
| `tryExtendOnLeave`？ | 若確認為舊政策 → 汰換為呼叫同一 cascade；若情境必須不同 → 文件化「為何兩條」後只抽共用 void |
| 前端誰打哪個 endpoint？ | 盤點後寫進本檔附錄；重疊則收斂到單一 API |

### C1 — 安全抽共用（行為不變）

- 抽出 `voidAttendanceArtifacts`（作廢 sign-in／LR + `reverseForSession(...,'retro_leave')`）兩處共用
- 測試：既有 leave／retro-leave feature 全綠

### C2 — 對齊尾端策略（行為可能變）

- 僅在 C0 裁定後，把非權威路徑改為權威 cascade
- 必備：Repair Manifest 思維、堂數不超 `SessionCount` 鎖測（陳禹慈類）

### C3 — TD-012（可與 C1 同 PR 或緊接）

- 手動點名改走 `AttendanceEffectsService`，刪 `AttendanceController` 重複 private 方法
- 不在本 phase 合併 `SwipeRfidController`（另案：冪等／lock）

---

## 時序

1. **A** merge → deploy（本任務）
2. **B0 → B1 → B2**；B3 等 GO
3. **C0 裁定**（可與 B 平行問）→ C1 → C2 → C3

## 完成定義

- A：死碼頁不在 tree；計畫檔在 `docs/plans/`
- B：顯示路徑單一 `isFullyPaid`；TD-073 金流子項標「部分清償」
- C：補請假 void 單實作；尾端策略單一或文件化雙路徑；TD-012／TD-069 狀態更新
