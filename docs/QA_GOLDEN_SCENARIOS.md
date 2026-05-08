# Golden Scenarios — CI 自動對應（無需人工勾選）

> **目的**：把 [`AI_REGRESSION_LESSONS.md`](AI_REGRESSION_LESSONS.md) 裡高頻坑，對應到 **CI 已跑的測試**，merge 前不需手動打勾。  
> **詳細規則**仍以 `AI_REGRESSION_LESSONS.md` 為準；本檔說明「§ → 誰在 CI 裡驗」。

## 怎麼用（零人工）

1. **Presubmit** 與 **CI** 會跑 [`scripts/golden-ci-report.sh`](../scripts/golden-ci-report.sh)：依 `origin/main...HEAD` 的檔案路徑標記 §0～§4 是否被本次 PR 觸及，並寫入 GitHub Actions **Job summary**。
2. **後端**路徑觸及 § → `ci.yml` 的 **PHPUnit** job（全量 Feature／Unit）必須綠燈。
3. **前端**路徑觸及 §3 → **Vite** job 內已含 `npm run test:calendar` + production build。
4. **部署後**的 production smoke（health、真機刷卡）仍由維運 SOP 處理，無法在 PR CI 內 100% 模擬。

---

## §0 全站 smoke（認證／路由／middleware）

| 自動化 |
|--------|
| 改 `api.php`、Auth、`AttachAuthUser`、`Kernel` → Golden report 標 §0；**PHPUnit** 跑通即涵蓋後端啟動與路由註冊；功能行為由各 Feature 測試覆蓋。 |

---

## §1 家長端／聯絡電話

| 自動化 |
|--------|
| 路徑含 `ParentPortal`、`ParentController`、`Student` model、`backend/tests/Feature/Parent*` → **PHPUnit**（含 `ParentPortalLoginIsolationTest`、`ParentPortalSubjectNameTest` 等）。 |

---

## §2 出缺勤／刷卡／堂次

| 自動化 |
|--------|
| 路徑含 `AttendancePage`、`SwipeRfid`、`ClassSession`、`Attendance*` tests → **PHPUnit** 全量。 |

---

## §3 智慧行事曆（合併／請假）

| 自動化 |
|--------|
| 路徑含 `SmartCalendar`、`calendarOccurrenceMerge`、`ScheduleController`、`lib/sessionDates.js` 等 → **Frontend CI** 強制 `npm run test:calendar`（見 `ci.yml` vite-build job）。 |

---

## §4 繳費／課程／帳務

| 自動化 |
|--------|
| 路徑含 `AlertController`、`Invoice`、`Payment`、`StudentClassController`、`Tuition*` tests 等 → **PHPUnit** 全量。 |

---

## §5 P0 紀律（Pi／force push／測試）

| 自動化 |
|--------|
| **無法**由程式代替人的決策；靠 **Presubmit**（分支命名、CHANGELOG 警告）、**規則文件**與 **Code review**。 |

---

## 擴充方式

1. 新坑寫入 `AI_REGRESSION_LESSONS.md`。  
2. 若有新「路徑模式」，編輯 `scripts/golden-ci-report.sh` 的對應 §。  
3. 若新坑可用測試涵蓋，補 **PHPUnit / frontend test**，不必再手動列清單。
