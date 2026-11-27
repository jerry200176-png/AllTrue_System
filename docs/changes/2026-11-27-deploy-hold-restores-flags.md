## 2026-11-27 — 上線驗證沒過而停住時，把這次才打開的功能開關收回
<!-- release-notes: silent_ship=silent-2026-11-27-deploy-hold-restores-flags -->
<!-- silent-reason: Deploy executor failure path only; no change on a successful deploy. -->

- 部署停在 hold（exit 12：驗證沒過但範圍含 migration，不回滾程式）時，現在也會還原這次要求打開的純後端旗標（`POP_INAPP_STANDING_POLICY`）並重建 config cache；有前端畫面的三個旗標已編進 bundle，維持不動以免前後端不一致；exit 12 與 exit 13 兩種 hold 都會再刷新 FPM web workers，網頁請求不會繼續用到剛打開的值（#4478）。要求「關」的開關照舊不會被復原。驗證全過後才出現的 Sentry-only 異常不收回旗標。
