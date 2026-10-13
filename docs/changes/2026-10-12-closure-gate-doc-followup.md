## 2026-10-12 — docs: 修正結案關卡指南的版本核對與回滾說明（#3898 review）
<!-- release-notes: silent_ship=silent-2026-10-12-closure-gate-doc-followup -->
<!-- silent-reason: 只改內部文件，沒有使用者可見變更。 -->
- Gate 3：只要求 backend_sha 等於核准 SHA；frontend_build_sha 僅在有重建前端時比對；需對照成功的 deploy.yml run，避免回滾後 manifest 未改回的誤判。Gate 6：migration 只走 deploy.yml（POP 不執行 schema migration）；POP approve 即開始執行，沒有另一道 apply。Refs #3898
