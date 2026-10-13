## 2026-10-13 — chore(privacy): workflow sources use ids instead of personal names (#3627)
<!-- release-notes: silent_ship=silent-2026-10-13-workflow-names-to-ids -->
<!-- silent-reason: 只改 CI／營運 workflow 的 case 代號、確認字串與內部查詢，教職員看不到差異。 -->
- `production-case-dump.yml` 的 case 代號改為中性代號（分校 ID／用途），老師查詢改由該學生課程的 TeacherID 取得，不再以姓名查詢。兩個 POP 修復 workflow 的名稱、確認字串與暫存目錄改用學生 ID。已存在正式站紀錄的 operation／approval／idempotency 代號維持不變。Refs #3627
