## 2026-11-11 — fix(pop): in-app resolve reads the deployment manifest through one shared reader and explains a refusal
<!-- release-notes: silent_ship=silent-2026-11-11-pop-inapp-resolve-manifest-diagnostic -->
<!-- silent-reason: 只影響 POP 內部操作 inapp-report-resolve-after-deploy 的上線證據檢查與錯誤說明；教職員畫面與家長入口不變 -->

- `inapp-report-resolve-after-deploy` 與 Pi 本機執行器現在共用同一個 `deployment.json` 讀取器（`DeploymentManifest`），預設位置與部署腳本寫入的檔案固定對應，並有測試鎖定。
- 讀不到、讀不了、格式錯誤或沒有有效 SHA 時，dry-run 回報新的 `deployment_manifest_unreadable`（仍然拒絕、不退回「相信 run」）；與 `deploy_run_not_in_production`（讀到了但 SHA 不同）分開。
- dry-run 結果多帶 `manifest_state` 與實際讀到的 `manifest_shas`（公開 SHA，不含路徑），下次失敗可直接判讀。
