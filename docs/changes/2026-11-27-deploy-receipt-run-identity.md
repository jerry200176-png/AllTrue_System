## 2026-11-27 — 上線紀錄多記「哪一次執行」
<!-- release-notes: silent_ship=silent-2026-11-27-deploy-receipt-run-identity -->
<!-- silent-reason: Deployment record only; nothing a user sees changes. -->

- `deployment.json` 多兩個欄位 `run_id`、`run_attempt`：是哪一次 deploy.yml 執行把這個版本換上去的（#3503）。舊版寫入程式不認得這兩個參數時不會傳，所以回滾和部署較舊的版本不受影響。
