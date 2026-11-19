## 2026-11-19 — chore(db): drop the unused question-bank and assessment-attempt tables (TD-090/TD-091)
<!-- release-notes: silent_ship=silent-2026-11-19-drop-unused-assessment-tables -->
<!-- silent-reason: 丟棄 7 張沒有任何程式讀寫、正式資料皆為 0 列的空表；畫面與行為都不變。 -->

- 丟棄 `question_banks`、`question_bank_items`、`question_bank_audit_logs`、`assessment_attempts`、`assessment_answers`、`assessment_question_snapshots`、`assessment_audit_logs`（Founder GO 2026-11-19）。`assessments`、`assessment_results`、`assessment_remediation_actions` 保留。
