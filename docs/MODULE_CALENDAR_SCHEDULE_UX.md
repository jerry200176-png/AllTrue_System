# 行事曆與調課 UX／產品規格

> 版本：2026-08-01 · Bounded context：`SmartCalendar.vue`、`ScheduleDiscrepancyPage.vue` · Issue：[便於追蹤](https://github.com/jerry200176-png/AllTrue_System/issues/1605)

## 1. 使用者問題

主任在行事曆中不是單純「看課表」，而是要處理某一堂課的時間、老師、請假與衝突。現有畫面把月份、週次、跳日、日／週檢視、教室、搜尋、老師請假、快速排課與容量圖例放在同一個工具列；課程卡的狀態也需要跨 `StudentClass`、`ClassSession` 與 `schedules` 合併。若沒有先選定「哪一堂」和「要做什麼」，主任容易在拖曳或 modal 中猜測後果。

本模組的目標不是重寫 calendar data source，而是讓同一份 occurrence 真相更容易被讀懂、定位與安全操作。

## 2. 研究與具體採用規則

### 專案文件與回歸基準

- `GUIDE_SMARTCALENDAR_REFACTOR.md`：`calendarOccurrenceMerge.js` 是週檢視唯一合法合併路徑；presentational 元件保持無副作用。
- `ADR_004_atomic_reschedule_boundary.md`：調課必須走 `RescheduleSessionService::execute()` 原子交易；只有 `committed=true` 才顯示成功。
- `ADR_005_scheduling_named_command_boundaries.md`：保留多個 task surface，但每個 mutation 使用具名 domain command。
- `MANUAL_SCHEDULE_DATE_SEMANTICS.md`：日期、星期、時段顯示不得以瀏覽器 UTC 推算取代本地日期語意。
- `AI_REGRESSION_LESSONS.md`：G-007、R25/R25b、R39、R43、R44、R47、R48、R49、R69、R71 與 R88。

### 企業產品與開源原始碼

- Google Calendar 官方操作：日期切換放在固定導覽區，日／週／月是清楚的 view switch；採用「現在在哪一週」與「回到今天」優先。
- Microsoft Outlook Scheduling Assistant：把「可用／衝突」視為選擇時段的主要資訊，採用先顯示衝突，再提供替代時段的結構。
- FullCalendar 原始碼與官方事件互動模型：拖曳與 resize 是明確事件邊界，drop 後由事件處理器決定是否提交；採用「選取 → 顯示影響 → 提交」而不是拖曳即靜默完成。
- `vbenjs/vue-vben-admin` 原始碼：table action 以 scoped action menu 管理次要操作；採用頁首、篩選、資料區、操作區的穩定層級。

## 3. UX 方案

### SmartCalendar

1. 頁首先回答「目前看哪個分校／日期／檢視」，次要管理功能收進清楚的區域。
2. 日期導覽提供上一個／下一個／回到今天，且顯示完整日期範圍，不只顯示月份與抽象週次。
3. 課程卡固定顯示學生、科目、時間、有效授課老師與唯一狀態；請假、調課、代課、衝突、漏點名、未填評量使用一致語意色與文字。
4. 調課、代課、請假、取消、恢復正班使用命名操作；不得用「操作」或只靠右鍵作為唯一入口。
5. 送出 mutation 前顯示原堂、目標堂、影響（原時段是否釋出、老師／堂次／評量是否變更）；失敗時保留原 context。
6. 手機改為單欄日期／課程清單；主要操作不依賴水平滑動，資料網格若需橫向瀏覽也不得包住主要 CTA。

### ScheduleDiscrepancyPage

1. 預設只回答「今天還有哪些課表回報要我處理」。tab 顯示狀態數量，保持 pending／處理中／已解決的狀態語言。
2. 每筆回報先顯示老師、學生、原始時段、建議時段與影響；「處理」展開後才顯示備註與稽核欄位。
3. `已確認`、`標記已修正` 是明確一級動作；處理說明不足時在同一區域指出原因，不讓按鈕無聲 disabled。
4. 手機使用 card list；長備註可換行，不把 table 欄位壓縮成工程代碼。

## 4. 資料與權限紅線

- 不改 `calendarOccurrenceMerge.js` 的合併語意；若需修正，先補 characterization fixture 並跑 `npm run test:calendar`。
- 調課定位至少包含 `student_class_id + old_date + old_start_time`；同課程同日多堂不可只用日期猜測。
- `leave` 優先於同日 `scheduled` overlay；同一 `ClassSession.id` 只渲染一張卡；effective teacher 以時段級例外為準。
- 請假不可退化成直接寫 `schedules`；調課不可退化成前端多段寫入。
- 不新增資料庫欄位、不改 Charge／Invoice／Payment 真相；保留分校授權與既有 API 契約。

## 5. 測試與驗收

- 單元：狀態／action mapping、同日多堂精準定位、leave＋scheduled merge、代課 effective teacher、錯誤訊息與狀態轉換。
- E2E：正常、空資料、loading、API error、長姓名／備註、資料很多；390／412／768／1280／1440px。
- E2E：日／週檢視、日期導覽、老師／學生篩選、課程卡狀態、調課／代課／請假入口與回報處理；不執行真實 production mutation。
- Accessibility：tabs、filters、day cells、cards、dialogs 的鍵盤 focus、ARIA、可見 CTA。
- Layout：`scrollWidth <= clientWidth`；手機主要 action 不因水平滑動或 hidden overflow 消失。
- Release：Vitest、calendar regression、Vite build、lint、design guard、Playwright、CI、deploy、health、version 與 production desktop/mobile evidence。

## 6. 風險與回滾

高風險是「畫面看似正確但 occurrence 真相分裂」。本 PR 只做顯示／互動層與必要的 adapter；若 production acceptance 發現狀態與後端不一致，立即回滾本 PR，不直接修 DB。任何 mutation 錯誤保留 modal／expanded row，不樂觀宣告完成。
