# 第三方 / 供應商依賴風險盤點（#892）

> 目的：把「這個系統實際依賴哪些外部服務、每個服務出問題會影響什麼、密鑰放在哪」寫成單一可查表，對標大公司的 vendor risk register / third-party dependency inventory。
> 範圍：僅盤點程式碼、`.env.example`、`composer.json`/`package.json`、既有 runbook 裡**實際引用**的服務；不含供應商本身的資安認證/合約條款（那需要人工向供應商索取，非程式碼可得）。
> 這是唯讀盤點文件，不涉及任何密鑰輪替或帳號變更——真正的 secret rotation drill 是 #879，需要正式生產存取權限才能執行。

## 核心第三方服務

| 服務 | 用途 | 密鑰/憑證位置（env var 名稱，非實際值） | 若服務中斷/外洩的影響 | 現有防護 |
|---|---|---|---|---|
| **Sentry** | 前後端錯誤追蹤（`@sentry/vue`、`sentry/sentry-laravel`） | `SENTRY_LARAVEL_DSN`、`SENTRY_CSP_REPORT_KEY`（backend `.env.example`） | 中斷：失去錯誤可見度，不影響服務本身運作。外洩：DSN 外流可能被灌垃圾事件（noise），不含使用者資料外洩風險（Sentry SDK 設定需另查是否有 PII scrubbing） | Sentry 官方 SaaS，未見自架 |
| **LINE Messaging API** | 家長/老師 LINE 綁定與通知（`LineID` 欄位、`StudentLineBinding` 資料表） | `LINE_CHANNEL_ACCESS_TOKEN`、`LINE_CHANNEL_SECRET`（backend `.env.example`） | 中斷：通知/綁定功能失效，核心排課/帳務不受影響。外洩：token 外流可被冒用發送訊息給已綁定使用者（釣魚風險）| webhook secret 見 #1139（`Campus.TelegramWebhookSecret` 對應的是 Telegram，非 LINE，兩者要分開追蹤） |
| **Telegram Bot API** | 部分分校通知（`TelegramID`/`TelegramID1`/`TelegramID2` 欄位） | Bot token 未見於 `.env.example`，需另查 production 環境變數；`Campus.TelegramWebhookSecret` 目前為 NULL（**#1139 已追蹤此缺口——webhook 沒有 secret_token 驗證**） | 中斷：該管道通知失效。外洩：webhook 無 secret 驗證 = 任何人都可以偽造 webhook 呼叫，是本表中風險最高的一項 | ⚠️ 見 #1139，尚未修復 |
| **Pusher**（`pusher/pusher-php-server`） | 即時廣播（`config/broadcasting.php`），前端 `chatRealtime.js` 走 `/api/v1/broadcasting/auth` | Pusher app key/secret，未見於檢查過的 `.env.example` 片段，需另查 | 中斷：即時聊天/通知退化為輪詢或無即時性，非核心功能中斷 | 標準 Pusher SaaS |
| **UptimeRobot** | 外部健康檢查監控（主站 + `/health`），不依賴 GitHub runner | `UPTIMEROBOT_API_KEY`（依 `docs/OPERATIONS_RUNBOOK.md` 引用，未設定則略過告警） | 中斷：失去外部可用性監控視角（本身不是服務依賴，是監控依賴） | 已有 daily `pi-health.yml` + Pi 本機 cron 作為補位 |
| **Google Drive**（備份） | Pi 資料庫/檔案備份落地（依 `docs/OPERATIONS_RUNBOOK.md`、`MEMPALACE_OPERATIONS_HANDBOOK.md` 引用） | 服務帳號憑證，存於 Pi 本機（不在此 repo） | 中斷：備份無法上傳，本地備份仍在但異地備援消失。外洩：憑證外流可讀取/刪除備份 | 有 monthly restore drill（依 runbook） |
| **GitHub Actions**（CI/CD） | 測試、PHPStan、部署 pipeline | Repo/Org secrets（`PI_SSH_USER`、`PI_SSH_HOST` 等，見 CLAUDE.md G-006） | 中斷：CI/deploy 卡住（本 session 就實際遇到 pull_request 自動觸發失效的案例，已用 `workflow_dispatch` 手動繞過） | branch protection + required checks；已知格式限制見 G-006 |
| **maatwebsite/excel**（`phpoffice/phpspreadsheet` 底層） | 帳單/名冊匯出匯入 | 無外部帳號，純函式庫 | 函式庫本身 CVE 風險，非服務中斷風險 | 見 #977，已於本 session 確認 `phpspreadsheet` 已是修補版 1.30.6 |

## 容易誤判為第三方依賴、但實際不是的項目

- **`@supabase/supabase-js`**（frontend `package.json`）：**不是真的 Supabase 服務依賴**。`frontend/src/supabase.js` 是一個手寫的相容層，模仿 Supabase client 的 `.from().select().eq()` 鏈式語法，但底層全部呼叫自家 Laravel API（`/api/v1/...`），純粹是介面相容命名，沒有任何流量真的送到 Supabase。盤點時不應把它算進「第三方風險」，但套件命名容易誤導未來接手的人以為系統真的接了 Supabase，值得在此記錄澄清。

## 已知缺口（尚未做的部分，對齊 #879/#892 母 issue 範圍）

- 沒有正式的「供應商清單 + 合約/資料處理協議（DPA）追蹤表」——本文件只涵蓋技術面（服務用途、密鑰位置、中斷影響），不涵蓋法務面（供應商是否簽過資料處理合約、資料保存地區）。
- Telegram webhook 缺 secret_token 驗證（#1139）是本次盤點中發現的**最高風險缺口**，建議優先於其餘 vendor risk 項目處理。
- 沒有 secret rotation 排程或紀錄（#879 範圍）；本文件只列出「密鑰放在哪個 env var 名稱」，不代表這些密鑰有定期輪替機制。
- Pusher app key/secret 的實際存放位置本次未能在 `.env.example` 中確認，需要下一輪對 production 環境變數命名做確認（非此 repo 唯讀盤點可得）。

---
_本文件為唯讀程式碼/設定檔盤點產出，非正式供應商合約稽核。對應 GitHub #892。_
