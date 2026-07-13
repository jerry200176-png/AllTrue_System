# DevOps 手動任務追蹤

本目錄記錄了 7 項需要「真人手動執行」的 DevOps 任務 — AI agent 在 sandbox 環境中無法完成，需要 CEO / 平台團隊 / 外部人員實際操作。

## 任務清單

| # | 任務 | 需要誰 | 優先級 |
|---|------|--------|--------|
| 1 | [SSH 登入 Pi 執行主機強化腳本](./01-pi-host-hardening.md) | CEO | 🔴 Critical |
| 2 | [調查 GitHub Actions 從未觸發原因](./02-github-actions-investigation.md) | CEO | 🔴 Critical |
| 3 | [平台端部署 Chromium 瀏覽器容器](./03-chromium-browser-container.md) | 平台團隊 | 🔴 Critical |
| 4 | [@BotFather 撤銷並重發 Telegram token](./04-telegram-bot-token-renewal.md) | CEO | 🟠 Medium |
| 5 | [SSH 登入 Pi 執行憑證清查 + backup audit](./05-pi-credential-audit-and-backup-audit.md) | CEO | 🟠 Medium |
| 6 | [確認 Pi 上 cron job 正常運作](./06-pi-cron-jobs-verification.md) | CEO | 🟠 Medium |
| 7 | [採購第二台 Pi + DB replication](./07-purchase-second-pi-and-db-replication.md) | CEO | 🟠 Medium |

## 注意
這些 Issue 無法直接從 GitHub API 建立（AI agent 的 GitHub App token 無 Issues 寫入權限），
因此寫成 markdown 檔案存放在此目錄。CEO 請手動到 GitHub → Issues → New 逐一建立。
