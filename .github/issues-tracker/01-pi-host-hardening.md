# [DevOps] SSH 登入 Pi 執行主機強化腳本（pi-host-hardening.sh）

## 要做什麼
SSH 登入 AllTrue Pi 主機（59.120.129.126:2222），執行 `sudo bash scripts/pi-host-hardening.sh`。

腳本內容（PR #1206，已合併至 main）：
1. UFW 防火牆設定（只開 2222/HTTP/HTTPS）
2. SSH 金鑰登入強制、禁用密碼登入
3. fail2ban 安裝與設定
4. 停用不必要系統服務
5. 自動驗證每步驟結果（共 211 行）

## 為什麼 sandbox 做不到
本 AI agent 無 SSH 金鑰，無法遠端登入 Pi 主機。腳本需要 sudo 權限在 Pi 上執行。

## 誰來做
**CEO**（或擁有 `admin@59.120.129.126` SSH 金鑰的人）

## 背景
- 自 4/20 Mirai 攻擊事件後已記錄 **84 天**，從未執行
- 執行方式：`ssh -p 2222 admin@59.120.129.126 && sudo bash /home/admin/scripts/pi-host-hardening.sh`
- 預計耗時：約 2 分鐘（含自動驗證）
