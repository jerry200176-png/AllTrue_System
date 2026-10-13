## 2026-10-12 — ci: 密碼外洩檢查改接進必要的 Control Plane 檢查，改為預設擋下＋只減不增的既有清單
<!-- release-notes: silent_ship=silent-2026-10-12-workflow-secret-echo-guard-wiring -->
<!-- silent-reason: 只調整 CI 檢查腳本與其接線，沒有任何使用者可見變更。 -->
- `workflow-secret-echo.test.mjs` 現在在 ci.yml 的 `Control Plane Contract Lint`（必要檢查）執行，以 YAML 解析後的 run 字串分析：密碼變數、`.env` 讀取、環境變數傾印只要不是少數已知安全寫法，就必須加 `# secret-echo-allow: <理由>`。既有命中行記在 `scripts/ci/workflow-secret-echo.baseline.json`（以行內容雜湊為鍵、只能減少），新增或修改的行一律擋下。Refs #1387 #3884
