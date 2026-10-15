## 2026-10-15 — ci: cloud 可用的 Phase-A 分診回寫路徑（預設關閉，每次執行需 Founder 核准）
<!-- release-notes: silent_ship=silent-2026-10-15-bug-phase-a-request-path -->
<!-- silent-reason: 只新增預設關閉的 GitHub Actions 工作流程、檢查腳本與測試；網站與 App 的功能、畫面和資料都不變 -->
- 新 `bug-phase-a-request.yml`：merge request 檔後，先檢查 ID／issue／回覆（§3.8、個資）並唯讀確認每筆仍是 `new`，再由 `production-activation` 等 Founder 核准該 run 才寫入；寫入重用 `bug-phase-a-triage.yml` 的 Pi 腳本。
- 開關 `PHASE_A_REQUEST_ENABLED` 預設未設定＝略過；開啟需 Founder 另外核准。
