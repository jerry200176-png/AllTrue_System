## 2026-11-17 — feat(billing): existing-course prepaid allocation core
<!-- release-notes: silent_ship=silent-2026-11-17-existing-course-credit-allocation-core -->
<!-- silent-reason: 僅新增未接入 API／介面的純計算核心與隔離測試；教職員操作及正式資料不變。 -->

- 為 in-app #563／#5404 準備既有課程的已付額度配置計算：保留目的課程的原堂數與已上紀錄，核對同價同堂長、總額度與金額守恆，以及必須明確確認的來源預約釋出數量。
- 沿用 Brick Math 與 Eris；尚未接上資料寫入、原子交易、還原、API 或操作介面，不代表轉用功能已完成或已可操作。
