## 2026-11-18 — chore(ops): POP past-scheduled cancel also covers one closed course's seven sessions (in-app #550)
<!-- release-notes: silent_ship=silent-2026-11-18-pop-cancel-past-scheduled-3380 -->
<!-- silent-reason: 只擴充既有 POP 營運操作與 workflow 的案件選項與測試，尚未對正式站執行，教職員看不到差異。 -->
- 既有的過期堂次取消作業新增一案：一門已結束課程底下七堂已過期仍標「預排」的堂次，改為取消；只改堂次狀態，不動帳務，可回復。Refs #5361
- 取消與執行憑證共同提交；還原另需本人核准，沿用 Pi 原生排程執行。部分還原保留新活動並停止，重新核准才續接；此 PR 不執行正式資料修復。
