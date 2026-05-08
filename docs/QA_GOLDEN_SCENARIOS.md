# Golden Scenarios — PR／AI 改動後最快驗收

> **目的**：改程式後不用憑感覺；勾一圈 **5～10 分鐘** 能逮住最常再犯的坑。  
> **詳細規則**仍以 [`AI_REGRESSION_LESSONS.md`](AI_REGRESSION_LESSONS.md) 為準；本檔只列**最小可執行檢查**。

## 怎麼用

1. 開 PR 或請 AI 合併前：依**本次改動模組**勾選對應區塊（不必全勾）。
2. 改了 `AttendancePage.vue`、`SmartCalendar`、`StudentClass`、繳費／Invoice → **必勾該區**。
3. 只做 docs／純文案 → 至少勾 **§0 全站 smoke** 裡的 health（若可連線）。

---

## §0 全站 smoke（常駐，約 1 分鐘）

- [ ] `GET /api/v1/health` 回 200、`status` 為 ok（部署後或改 nginx／後端啟動相關時必做）
- [ ] 主任／老師 **登入** 成功，token 寫入 session（改 `auth`／middleware 時必做）
- [ ] 分校選擇後列表／儀表板仍 **限本分校**（改 `require_campus`／raw query 時必做）

---

## §1 家長端／聯絡電話（改 `parent`、`Student`、登入 API 時勾）

- [ ] 家長登入：學生若只有填「家長手機」在 `parent_phone`，仍可登入（見 `AI_REGRESSION_LESSONS` §R10）
- [ ] 家長端評量／科目顯示為**中文或一致標籤**，不出現裸 `English` 當 UI 主文案（§R11）
- [ ] 家長相關 API：**先驗證 StudentID 歸屬**，再回業務狀態碼；勿用 403/409 差異洩漏他人資料（§R17）

---

## §2 出缺勤／刷卡／堂次（改 `AttendancePage`、`SwipeRfid`、`ClassSession`、`POST attendance` 時勾）

- [ ] 老師「補建並點名」送出含 **`StudentID`**，且日期可選**非今天**（若 UI 有補登）（§R14）
- [ ] 管理員可查**指定日期或區間**出勤紀錄，不是只能看今天（§R12、§R15）
- [ ] 補課／調課建立後，**該日**在行事曆／待點名／評量可見（補課有對應 `ClassSession`）（§R13）
- [ ] 改 `AttendancePage.vue` `<script setup>` 後：**整頁可開、無 TDZ 白屏**；helper 宣告在 `ref` 初始化之前（§R16）

---

## §3 智慧行事曆（改 `SmartCalendar`、`calendarOccurrenceMerge`、請假／例外合併時勾）

- [ ] 修改合併邏輯後執行：`cd frontend && npm run test:calendar`（§R25、§R25b）
- [ ] 同日 **請假** 與 **scheduled 例外**並存時，請假顯示不被吃掉（§R25）

---

## §4 繳費／課程／帳務（改 `AlertController::tuition`、`Invoice`、`Payment`、`StudentClass` 狀態、續報時勾）

- [ ] 變更前讀 [`DIRECTOR_PAYMENT_ALERT_RULES.md`](DIRECTOR_PAYMENT_ALERT_RULES.md)，**不擅自改提醒條件**
- [ ] **已繳**課程無法再核帳出第二筆付款／重複 Invoice（§R28）
- [ ] 課程結案／暫停：**未來 scheduled 堂次**一併處理，不只改 `Stop`（§R20）
- [ ] 加購堂數：使用者理解為**新批次**，導向 `new_course.id`（§R21）

---

## §5 只对 AI／工程師的 P0 纪律（不必每次手動測，但 merge 前心头过一遍）

- [ ] 未在 Pi production 路徑跑 `php artisan test`／`phpunit`（§R2）
- [ ] 改既有 `.php`／`.vue` 前 **CI 路線合法**（先測後改或符合 repo SOP）（§R3）
- [ ] 禁止 `git push --force`、禁止直推 `main`（§R5）

---

## 擴充方式

新增場景時：在 [`AI_REGRESSION_LESSONS.md`](AI_REGRESSION_LESSONS.md) 寫規則，再在本檔**加一行勾選項**並標 § 編號，保持一頁可讀。
