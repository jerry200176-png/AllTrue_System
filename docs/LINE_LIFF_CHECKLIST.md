# LINE / LIFF 家長入口上線檢查清單

上線前請確認以下項目已完成。

## 後台設定

- [ ] **LINE Developers**：已建立或使用既有 LINE Login Channel（或 Messaging API Channel）。
- [ ] **環境變數**（後端 `.env`）：
  - `LINE_CHANNEL_ACCESS_TOKEN`
  - `LINE_CHANNEL_SECRET`
  - `LINE_LIFF_ID`
- [ ] **環境變數**（前端建置）：`VITE_LIFF_ID` 與後端 `LINE_LIFF_ID` 一致。
- [ ] **Webhook**：LINE 後台 Webhook URL 指向 `https://你的網域/api/v1/line/webhook`，且可對外連線。

## LIFF

- [ ] **LIFF 應用**：在 LINE Developers 的 LIFF 頁籤已新增 LIFF App，Endpoint URL 為家長入口頁（例如 `https://你的網域/#/parent` 或 SPA 根網址）。
- [ ] **LIFF ID**：已將該 LIFF 的 ID 填入上述環境變數。

## 官方帳號選單

- [ ] **選單**：LINE 官方帳號的選單中已新增「家長入口」或「查看學習狀況」等按鈕。
- [ ] **連結**：按鈕連結為 `https://liff.line.me/{LIFF_ID}`（替換為實際 LIFF ID）。

## 綁定說明

- [ ] **使用者說明**：家長已知可輸入「綁定 學生姓名 手機號碼」完成綁定（例如官網、傳單或官方帳號歡迎訊息已說明）。
- [ ] **歡迎／追蹤訊息**：新好友加入時，LineWebhookController 的歡迎訊息已包含綁定說明（程式已實作，確認無被覆寫或關閉）。

完成以上勾選後，家長即可透過 LINE 進入家長入口並在綁定後免登入查看學習評量、出缺勤、剩餘堂數等。

## ⚠️ 多分校 / 共用網域的致命坑（2026-06-21 事故）

- **LINE `userId` 是 provider 範疇**：同一位家長在「不同 provider 的 channel」會拿到**不同 userId**。
  已實證：同一學生在新莊(11)與新莊中平(13)的 `student_line_bindings.line_user_id` 不同。
- **共用網域 → LIFF 解析必須用 `campus_id`，不能只靠 host**：13 新莊中平與 15 大安都用
  `daan.lifenet.com.tw`。`resolveLiff()` 純 host 比對只回「第一個」分校的 LIFF，導致另一分校家長
  拿到錯的 LINE Login channel → `liff.getProfile().userId` 與其綁定（不同 provider）對不上 → 自動登入 404。
  - 入口連結一律帶 `campus_id`（`LineWebhookController::getPortalUrl`）；`resolveLiff` 已改**優先 `campus_id`**，
    前端 `ParentPortal.vue onMounted` 以 `campus_id` 解析 LIFF 覆蓋 build-time 預設（`VITE_LINE_LIFF_ID`）。
- **檢查清單（多分校）**：每個分校的 LIFF（LINE Login channel）與 Messaging channel **務必在同一 provider**；
  否則綁定（來自 messaging webhook 的 userId）與 LIFF 登入（來自 Login channel 的 userId）永遠對不上。
  若分校各自獨立 provider，請改用**各分校獨立子網域**或一律靠 `campus_id` 解析，禁止共用網域 + host first-match。
- **未綁定的家長**：入口已改為明確指引（手機+姓名登入 / 在 LINE 輸入「綁定 學生姓名 手機號碼」），
  不再顯示「正在自動登入…」的矛盾畫面（`autoLineNotBound`）。
