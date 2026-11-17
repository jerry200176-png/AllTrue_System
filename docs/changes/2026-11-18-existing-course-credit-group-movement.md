## 2026-11-18 — feat(billing): prepare atomic existing-course credit movement and inverse
<!-- release-notes: silent_ship=silent-2026-11-18-existing-course-credit-group-movement -->
<!-- silent-reason: 內部服務與收據保真整合，尚未開放新的 API 或操作入口。 -->

- 既有課程配置以同一交易保留已上堂數與原收款，僅釋出明確確認的未來未上課堂次，逐科記錄零和轉入／轉出及整批稽核；原收據的堂數、日期與款項保留。
- 整批還原重核帳務、教學參照與原生時段佔位，資料改變即拒絕；舊的單科還原不能拆解整批配置。尚未提供公開入口，不表示真實回報已完成轉用或驗收。
