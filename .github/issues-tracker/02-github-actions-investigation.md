# [DevOps] 調查 GitHub Actions 從未觸發原因

## 要做什麼
調查為何 GitHub Actions **從未執行過**（total runs = 0）。合併 PR 時需要暫時解除 branch protection 的 required_status_checks，這是不正常流程。

## 為什麼 sandbox 做不到
GitHub Actions 的設定需要在 GitHub 網頁 UI 上檢查（Settings → Actions），本 AI agent 無法存取 GitHub 設定頁面。此外 self-hosted runner 離線的原因可能涉及 GitHub 外部系統。

## 誰來做
**CEO**（repo owner，才有權限查看 GitHub repo Settings）

## 背景
- 2026-07-13 合併 4 個 PR 時發現：需強制關閉 branch protection 的 required_status_checks 才能合併
- 檢查 GitHub Actions 頁面：「total runs = 0」從未觸發
- 可能原因：self-hosted runner 離線 / 未設定 / token 權限不足
- 影響：無法自動化 CI/CD，每次合併都有人工風險
