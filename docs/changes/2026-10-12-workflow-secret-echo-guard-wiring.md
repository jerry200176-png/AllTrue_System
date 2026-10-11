## 2026-10-12 — ci: 密碼外洩檢查改接進必要的 Control Plane 檢查，並補上 9 種繞過寫法
<!-- release-notes: silent_ship=silent-2026-10-12-workflow-secret-echo-guard-wiring -->
<!-- silent-reason: 只調整 CI 檢查腳本與其接線，沒有任何使用者可見變更。 -->
- `workflow-secret-echo.test.mjs` 現在在 ci.yml 的 `Control Plane Contract Lint`（必要檢查）執行；改用 YAML 解析，補上指令替換、base64、`.env.*`、`DB_PASS`、`sh/dash/zsh -x`、同行 add-mask、`printenv VAR`、折疊/續行等繞過。Refs #1387 #3884
