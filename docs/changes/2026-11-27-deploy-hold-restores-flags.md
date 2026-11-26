## 2026-11-27 — 上線驗證沒過而停住時，把這次才打開的功能開關收回
<!-- release-notes: silent_ship=silent-2026-11-27-deploy-hold-restores-flags -->
<!-- silent-reason: Deploy executor failure path only; no change on a successful deploy. -->

- 部署停在 hold（exit 12：驗證沒過但範圍含 migration，不回滾程式）時，現在也會還原這次要求打開的功能旗標並重建 config cache；exit 12 與 exit 13 兩種 hold 都會再刷新 FPM web workers，網頁請求不會繼續用到剛打開的值（#4478）。要求「關」的開關照舊不會被復原。
