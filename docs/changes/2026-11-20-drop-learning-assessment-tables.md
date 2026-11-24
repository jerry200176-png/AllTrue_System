## 2026-11-20 — chore(db): drop the last three learning-assessment tables (TD-090)
<!-- release-notes: silent_ship=silent-2026-11-20-drop-learning-assessment-tables -->
<!-- silent-reason: 丟棄 3 張已無程式讀寫、正式資料皆為 0 列的空表；畫面與行為都不變。 -->

- 丟棄 `assessments`、`assessment_results`、`assessment_remediation_actions`。讀取端與 model 已在 #5555 移除；正式環境唯讀計數 2026-11-20 三表皆 0 列。學習檢測功能的資料表至此全部清除。
