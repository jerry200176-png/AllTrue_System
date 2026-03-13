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
