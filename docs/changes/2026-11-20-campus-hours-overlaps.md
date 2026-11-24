## 2026-11-20 — feat(payroll): 各分校時數報表列出「同時段在兩間分校都有課」的明細
<!-- release-notes: silent_ship=silent-2026-11-20-campus-hours-overlaps -->
<!-- silent-reason: 只在既有報表的 JSON 回應多一個 overlaps 欄位，畫面與 CSV 都沒變。 -->

- `GET /api/v1/finance/teacher-eligibility/campus-hours` 與唯讀金鑰版的每位老師多一個 `overlaps`：哪一天、哪幾堂課（開始、結束、分校、學生與課程的 id）在時間上重疊而且分屬不同分校。
- 這些堂次在各分校欄位各算一次、合計只算一次，所以各分校相加會比合計多；這個欄位用來查是哪幾堂。
- 時數的算法沒有改。
- 來源：Founder 2026-11-20（要查出重疊的是哪裡）。

Refs #5549
