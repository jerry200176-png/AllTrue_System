## 2026-10-14 — ci(deploy): Deploy 步驟不再含運算式，並加上 21,000 位元組上限檢查（#4051）
<!-- release-notes: silent_ship=silent-2026-10-14-deploy-expression-guard -->
<!-- silent-reason: 只改上線流程（deploy.yml）的內部寫法與 CI 檢查；主任、老師與家長的畫面和功能都不變 -->
- Deploy 步驟的指令區塊不再內嵌 `${{ }}`，數值改由 step `env:` 傳入，避免整個 deploy.yml 因超過 GitHub 運算式長度上限而停止解析、所有部署靜默中斷。
- 新增 `scripts/ci/workflow_expression_size.py`，每個 PR 檢查所有 workflow 的 run 區塊（含各種 YAML 寫法），超過 20,000 位元組即失敗。
