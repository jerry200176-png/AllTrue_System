## 2026-11-11 — fix(pop): in-app resolve reads the deployment manifest through one shared reader and explains a refusal
<!-- release-notes: silent_ship=silent-2026-11-11-pop-inapp-resolve-manifest-diagnostic -->
<!-- silent-reason: 只影響 POP 內部操作 inapp-report-resolve-after-deploy 的上線證據檢查與錯誤說明；教職員畫面與家長入口不變 -->

- `inapp-report-resolve-after-deploy` 與 Pi 本機執行器現在共用同一個 `deployment.json` 讀取器（`DeploymentManifest`），預設位置與部署腳本寫入的檔案固定對應，並有測試鎖定。
- 讀不到、讀不了、格式錯誤或沒有有效 SHA 時，dry-run 回報新的 `deployment_manifest_unreadable`（仍然拒絕、不退回「相信 run」）；與 `deploy_run_not_in_production`（讀到了但 SHA 不同）分開。
- dry-run 結果多帶 `manifest_state`、實際讀到的 `manifest_shas` 與 `manifest_source`（default／override，公開 SHA，不含路徑）。這讓下一次 dry-run 能看出原因（環境變數覆寫過期、網頁帳號讀不到檔案、或後端根目錄不同），本身不保證修好 report 526 的失敗。
- Pi 本機執行器仍固定讀部署位置，不受 `POP_INAPP_DEPLOYMENT_MANIFEST` 影響。
