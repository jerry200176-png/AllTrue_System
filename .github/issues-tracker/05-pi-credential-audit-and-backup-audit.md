# [DevOps] SSH 登入 Pi 執行憑證清查 + backup audit

## 要做什麼
SSH 登入 Pi 主機，執行兩項檢查：
1. **憑證清查**：檢查 `.env`、SSH 金鑰、API token 等機敏資訊是否殘留在不該在的地方
2. **Backup audit**：執行 `bash scripts/backup-audit.sh` 確認每日/每六小時備份正常運作

## 為什麼 sandbox 做不到
本 AI agent 無 SSH 金鑰，無法遠端登入 Pi 主機。腳本需要直接在 Pi 上執行。

## 誰來做
**CEO**（或擁有 `admin@59.120.129.126` SSH 金鑰的人）

## 背景
- 2026-06-28 已完成 git filter-repo 清理 git 歷史中的憑證
- ⚠️ 但 Pi 主機上的原始 `.env` / 暫存檔尚未清查
- 憑證清查腳本已就緒在 `.workspace/pi-credential-audit.sh`
- Backup audit 腳本在 Pi 上：`/home/admin/scripts/backup-audit.sh`
