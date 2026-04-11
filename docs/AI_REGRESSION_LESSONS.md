# AI／工程師防再犯紀錄（必讀）

本檔記錄**已發生過的產品／實作缺口**，避免下次改壞或改漏。  
**任何 AI Agent 或新進開發者**：請與 `AGENTS.md` 的 First-read 順序一併閱讀；修改下列模組前**先對照本檔**。

**不同工具如何接到本檔：** **Cursor** 透過 `AGENTS.md` 與 `.cursorrules`；**Claude Code** 讀根目錄 **`CLAUDE.md`**；**GitHub Copilot**／在 GitHub 上工作的 AI 讀 **`.github/copilot-instructions.md`**；人類協作者請看 **`CONTRIBUTING.md`**（皆連回本檔與繳費規則）。

相關專項規格：

- 主任儀表板「繳費提醒」完整規則：`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`
- 內部聊天、Bug 回報、使用者頭像（**含禁止回歸項**）：**`docs/AI_HANDOFF_CHAT_BUG_AVATAR.md`**

---

## 使用方式

1. 實作或重構觸及下方「關聯檔案」時，逐項確認行為是否仍符合「正確行為」。
2. 若引入新的高風險 regression，於本檔**以日期新增一節**（簡短：缺口 → 正確行為 → 關聯檔案／測試）。

---

## 2026-04-11 — 聊天頭像、Bug 附件／權限／紅點

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 頭像存成含 `APP_URL` 的完整 URL，區網開網頁時聊天／側欄破圖；Bug 主任誤以為能看全校；指派與狀態權限混在 `director` 路由。 |
| **正確行為** | 詳見 **`docs/AI_HANDOFF_CHAT_BUG_AVATAR.md`**：`PublicAvatarUrl`、只存 disk 路徑、主任／老師僅自己的 bug、僅 super_admin 狀態／mark-inbox、無指派、未讀紅點規則與路由順序。 |
| **關聯測試** | `ChatApiTest.php`、`BugReportApiTest.php`、`ProfileCenterApiTest.php`（頭像相關） |

---

## 2026-04-10 — 暫停課程、評量待審、繳費提醒、課程列表 UI

### A. 暫停課程（`StudentClass.Stop = 1`）仍出現在「待審評量」

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 課程已暫停，主任儀表板與學習評量頁仍出現該課的 `pending`／`changes_requested` 評量，誤以為還要填寫／審核。 |
| **正確行為** | 暫停課程的待審／需修改評量**不應列入**待審佇列與相關通知；**已核准、已退回等歷史**仍可查。 |
| **實作要點** | `LearningRecord` scope `excludePausedCoursePendingReview`；`LearningRecordController::index` 套用；`batchApprove` 僅限未暫停之 `StudentClass`；`NotificationSyncService::buildLearningNotifications` 排除暫停課程。 |
| **測試** | `tests/Feature/LearningRecordApprovalDeductionTest.php`（`test_paused_course_hides_pending_learning_record_from_index_but_keeps_approved_visible`）。 |

### B. 課程管理列表：暫停狀態「看不出來」

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 僅小標「已暫停」，整列與操作區與正常課程幾乎相同，主任沒有「真的暫停」的感受。 |
| **正確行為** | 整列背景／左側色條、科目欄上方 **明確 callout**（暫停說明）、學生群組標題 **「含暫停課程」**、展開的上課日期區塊視覺一致；**恢復**按鈕仍清楚可點。 |
| **關聯檔案** | `frontend/src/pages/CourseManagement.vue` |

### C. 主任儀表板「繳費提醒」漏提醒（堂數 0 堂、整類月結消失）

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | `GET /api/v1/alerts/tuition` 只查 `ScheduleMode = 'count'`，**整個月結制（`date`）被略過**；堂數制用 `RemainingSessions > 0 && <= 2`，**漏掉 0 堂**；畫面顯示「全數已繳」易誤導。 |
| **正確行為** | **必須**與 `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` 一致（堂數制 ≤2 含 0、月結 `settlement_day`、距繳費日 &lt; 5 天、逾期未繳等）。 |
| **關聯檔案** | `backend/app/Http/Controllers/AlertController.php`、`frontend/src/pages/DirectorDashboard.vue` |
| **測試** | `tests/Feature/TuitionAlertsApiTest.php`、`tests/Feature/NotificationApiTest.php`（`test_tuition_alert_endpoint_includes_low_sessions_even_when_paid`） |
| **營運手冊** | `docs/OPERATIONS_RUNBOOK.md`（繳費提醒／tuition API 說明需與上列規格文件同步） |

### D. 通知 API 測試與 `unread-count` 內建 sync

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | `GET /notifications/unread-count` 會先執行 `NotificationSyncService::sync`，手動建立的 `Type=tuition` 等**託管類型**可能被自動結案；測試預期的 `active_count` 與實際 sync 來源數不一致。 |
| **正確行為** | 測試用手動通知時使用**非** `managedTypes` 的 `Type`；或斷言與目前 `buildTuition`／`buildLowSessions` 等合併後筆數一致。 |
| **關聯檔案** | `backend/app/Http/Controllers/NotificationController.php`、`backend/tests/Feature/NotificationApiTest.php` |

---

## 檢查清單（快速）

修改以下路徑時，至少重跑相關 Feature tests：

- `LearningRecordController.php` / `LearningRecord.php` → LearningRecord 測試
- `AlertController.php`（`tuition`）→ `TuitionAlertsApiTest` + `NotificationApiTest`（tuition 相關）
- `NotificationSyncService.php` → `NotificationApiTest`
- `ChatService.php` / `ChatController.php` / `PublicAvatarUrl.php` / `AuthController.php`（`uploadAvatar`、`toAvatarUrl`）→ `ChatApiTest` + `ProfileCenterApiTest`
- `BugReportService.php` / `BugReportController.php` → `BugReportApiTest`
- `CourseManagement.vue` → 手動確認暫停列 UI；有腳本則 `npm run deploy`
