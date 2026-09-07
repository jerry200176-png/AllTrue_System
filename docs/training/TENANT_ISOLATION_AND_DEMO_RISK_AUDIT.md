# AllTrue 租戶隔離邊界、副作用分析與培訓演練環境風險審計報告
## Tenant Isolation Boundaries, Side-Effect Controls, and Training Environment Risk Audit

> **審計日期**：2026-09-07  
> **依據標準**：AllTrue 架構真實路由、代碼審計、資料庫模型及 `docs/AI_REGRESSION_LESSONS.md`  
> **結論宣告**：**「在正式生產庫建立 Demo 分校（如 CampusID=999）絕對非零風險（Non-Zero Risk）」**；嚴禁宣稱任何生產環境內的 Demo 租戶具備完全隔離。

---

## 一、 系統現有租戶隔離架構現況 (Current Tenant Architecture)

AllTrue 目前採用 **「共享資料庫、邏輯欄位隔離（Shared Database, Logical Column Isolation via `CampusID` / `branch_id`）」** 架構：

1. **實體儲存層 (Physical Storage Layer)**：
   - 所有的校區（Campuses）、學生（Students）、排課（ClassSessions）、點名（Attendances）、合約（StudentClasses）、收據（Receipts/Invoices）與評量（LearningRecords）均存放在同一個 MySQL 執行個體中。
   - MySQL 原生未啟用列級安全策略（Row-Level Security, RLS），隔離性 **100% 依賴應用程式層（Laravel Eloquent / Query Builder）於各 API 自行加入 `where('CampusID', $campusId)` 條件**。

2. **自增鍵序列共享 (Shared Auto-Increment Sequences)**：
   - 全域主鍵（`id`）為資料表共用自增整數（Auto-Increment INT/BIGINT）。
   - 若在生產資料庫插入測試資料，將永久消耗連續序號，且可能污染財務單據號碼、收據流水號與可審計事件流水號。

---

## 二、 外部副作用邊界審計 (External Side-Effect Boundaries)

經代碼層級追查，系統在資料異動時存在下列不可逆或外部可見之副作用通道：

### 1. LINE Bot 與 LINE Push 即時推播副作用
- **代碼錨點**：
  - `backend/app/Providers/AppServiceProvider.php`：`Notification::observe(NotificationObserver::class);`
  - `backend/app/Observers/NotificationObserver.php`：`created(Notification $notification)` 直接呼叫 `app(NotificationLineDispatcher::class)->dispatch($notification);`
  - `backend/app/Services/FeedbackPushNotifier.php`：家長與教師評量反饋通知
- **穿透風險**：
  - 一旦演練過程中觸發產生 `Notification`（例如排課衝突、待點名通知、低堂數提醒、代課異動），`NotificationObserver` 會立即在 `created` 生命週期攔截並非同步派送至真實 LINE API (`https://api.line.me/v2/bot/message/push`)。
  - 若測試學員綁定了真實手機或 LINE，或是系統回退至全域管理員通知管道，外部真實推播將立刻發出，引發家長或員工恐慌。

### 2. 背景定期同步任務 (Cron Jobs & Nightly Reconcile) 污染
- **代碼錨點**：
  - `backend/app/Services/NotificationSyncService.php`
  - `backend/app/Console/Kernel.php`（或排程修復與對帳工作）
- **穿透風險**：
  - `NotificationSyncService::sync()` 會掃描全庫堂數與繳費狀況。若測試分校的合約未繳費或堂數過低，將自動被匯總進入主任待辦與通知中心，直接影響正式分校主任的未讀計數與待處理案件數量。

### 3. 跨校授課教師（Cross-Campus Teachers）的真實課表衝突
- **代碼錨點**：
  - `backend/app/Http/Controllers/ScheduleController.php`
  - `frontend/src/pages/SmartCalendar.vue`
- **穿透風險**：
  - 正式教師可能同時任教於真實分校與演練分校。若在演練分校為其安排演練課堂，跨校衝突偵測會將該教師時段標記為「衝突佔用」，導致真實分校主任無法為該教師在該時段排課。

### 4. 財務與計費數據滲漏 (Financial Aggregation Contamination)
- **代碼錨點**：
  - `backend/app/Http/Controllers/TuitionReportController.php`
  - `backend/app/Http/Controllers/ParttimePayrollController.php`
- **穿透風險**：
  - 財務報表若有任一查詢統計未嚴密加上 `where('CampusID', ...)`，演練資料的收費紀錄、結案狀態或兼職鐘點費將計入全體財務彙總，造成對帳混亂與稅務申報不一致。

---

## 三、 為何「CampusID=999」並非零風險？(Why Demo Tenant is NOT Zero-Risk)

| 潛在風險層面 | 具體故障機制 | 嚴重性 |
| :--- | :--- | :---: |
| **資料庫連線死鎖與寫入競爭** | 在正式生產庫高頻率批次點名或清空測試資料，會造成 row-level locks 或 table metadata locks，波及正式營運交易。 | **高 (High)** |
| **誤刪正式資料風險** | 若撰寫「一鍵重置 Demo 分校」腳本（如 `DELETE FROM ... WHERE CampusID=999`），若程式邏輯有瑕疵、外鍵級聯設定不當，可能連帶刪除共享主檔（如共用教師、教材單元）。 | **致命 (Critical)** |
| **外部 API 配額消耗與扣款** | LINE Messaging API 有每月免費則數上限。演練發送大量測試推播會消耗正式帳號額度，甚至產生超額扣費。 | **中 (Medium)** |
| **人為操作走錯分校** | 正式主任登入時若未注意當前分校為 Campus 999，在測試分校輸入真實新生報名與合約，事後資料遺失不可挽回；反之亦然。 | **高 (High)** |

---

## 四、 培訓演練安全替代方案與建議路徑 (Recommended Paths)

若要在正式環境安全開展培訓，必須遵守以下鐵律：

1. **唯讀或受限情境演練（推薦做法）**：
   - 培訓時指導學員以「瀏覽、定位、篩選、展開查看、填寫表單但不按最終送出」為主軸。
   - 實際送出僅限於當日真實排課之正常點名與評量（由實際任課老師與主任親自操作當日工作）。
2. **所有演練備註強制標籤**：
   - 任何演練文字統一加上前綴 `[培訓演練 20260908]`，以便日後追蹤與稽核。
3. **若未來需建置獨立 Demo 環境之不可妥協先決條件**：
   - 必須是 **獨立的 Staging / Demo 資料庫實例（Separate DB Instance）**，徹底與 Production DB 實體隔離。
   - 外部服務環境變數全部無效化（`LINE_BOT_CHANNEL_TOKEN=""`, `TELEGRAM_BOT_TOKEN=""`）。
   - 需有創辦人書面授權與專屬部署管道。
