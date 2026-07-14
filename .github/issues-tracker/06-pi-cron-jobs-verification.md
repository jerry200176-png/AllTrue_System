# [DevOps] 確認 Pi 上 cron job 正常運作

## 要做什麼
SSH 登入 Pi 主機，檢查 crontab 是否正常執行、所有排程任務（nightly-backup、sixhour-backup、monitor-alert 等）是否按時觸發。

## 為什麼 sandbox 做不到
需要 SSH 登入 Pi 直接檢查 `crontab -l`、查看 `/var/log/cron`、檢查系統日誌。本 AI agent 無 SSH 金鑰。

## 誰來做
**CEO**（或擁有 `admin@59.120.129.126` SSH 金鑰的人）

## 檢查項目
1. `crontab -l` — 看排程是否仍存在
2. `journalctl -u cron | tail -20` — 看 cron daemon 是否活著
3. 檢查 `/home/admin/backups/` 目錄是否有今日備份檔
4. 確認 `nightly-backup.sh` 和 `sixhour-backup.sh` 的最後執行時間
