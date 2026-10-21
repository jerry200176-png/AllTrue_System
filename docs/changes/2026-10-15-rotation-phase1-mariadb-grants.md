## 2026-10-15 — fix(ops): DB 帳號輪替 Phase 1 支援 MariaDB 的 SHOW GRANTS，並在建立前檢查殘留帳號（#4160）
<!-- release-notes: silent_ship=silent-2026-10-15-rotation-phase1-mariadb-grants -->
<!-- silent-reason: 只改正式機 DB 帳號輪替的 GitHub Actions 步驟與其測試；網站與 App 的功能、畫面和資料都不變 -->
- `deploy.yml` 的 `phase1-create` 會先剝除 MariaDB `SHOW GRANTS` 附帶的舊帳號認證子句（`IDENTIFIED BY PASSWORD`／`IDENTIFIED VIA … USING …`），再把權限重放給新帳號；剝除後仍有認證字串時照樣拒絕，而且全部檢查通過前不輸出任何敘述。
- 建立新帳號前，先以舊帳號唯讀計算 `mysql.user` 中的 `atr_*` 帳號（排除目前帳號本身與 Phase 3 已鎖定的前任）；有未鎖定的殘留或讀不到 `mysql.user` 都拒絕並要求 Founder 檢視。log 只記數量。
- 輪替 job 改為 `needs: contract-test`，合約測試沒綠就不會碰正式憑證；runbook `docs/incidents/1387-staged-rotation-runbook.md` 同步更新。
- 新增 `scripts/tests/test_principal_rotation_grant_replay.py`，CI 與 deploy 合約測試都會執行。
