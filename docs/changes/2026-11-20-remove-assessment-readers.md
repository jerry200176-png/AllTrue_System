## 2026-11-20 — chore(assessment): stop reading the empty learning-assessment tables (TD-090)
<!-- release-notes: silent_ship=silent-2026-11-20-remove-assessment-readers -->
<!-- silent-reason: 移除家長頁「檢測進度」卡與學習歷程的檢測來源；三張表在正式環境都是 0 列，卡片本來就不顯示，畫面不變。 -->

- 家長頁不再讀取 `assessments`／`assessment_results`／`assessment_remediation_actions`，學習歷程也不再合併檢測分數；刪除三個 model。正式環境唯讀量測 2026-11-20 三張表皆 0 列。資料表本身保留，丟棄要另由 Founder 批准 migration。
