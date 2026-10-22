## 2026-10-15 — ci(deploy): Deploy 步驟的遠端腳本移到 checked-in 檔案，並加上 run 區塊大小檢查（#4051）
<!-- release-notes: silent_ship=silent-2026-10-15-deploy-run-block-size -->
<!-- silent-reason: 只改上線流程（deploy.yml）的內部寫法與 CI 檢查；主任、老師與家長的畫面和功能都不變 -->
- Deploy 步驟送到 Pi 的部署腳本改放在 `scripts/production-deploy/pi-remote-deploy.sh`，數值由 step `env:` 傳入並以純文字代換，Pi 收到的內容與之前逐位元組相同；Deploy 的 run 區塊不再含 `${{ }}`，不會再逼近 GitHub 21,000 位元組的運算式上限。
- 新增 `scripts/ci/workflow_expression_size.py`，每個 PR 檢查所有 workflow：含 `${{ }}` 的 run 區塊達 18,000 位元組即失敗，無法精確量的 YAML 寫法一律失敗。
- Current-main 整合保留 private-storage 權限 helper、FPM 失敗狀態碼診斷與既有 rollback；以合成值核對 main605 的原 heredoc 與抽出後的輸出逐位元組相同。防再犯條目使用 R158，避免覆蓋既有 MariaDB R157。
- `rollback-readiness.sh` 會在呼叫端 checkout 執行 revert/reset；驗證必須先 commit，再於可丟棄的全新 clone 執行，不能把畫面上的 sandbox 標籤當成隔離保證。
