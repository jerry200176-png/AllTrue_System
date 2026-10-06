## 2026-10-06 — chore(privacy): recovery workflow filter, encrypted acceptance report, names out of allowlist replies (#3627)
<!-- release-notes: silent_ship=silent-2026-10-06-workflows-ids-only-part3 -->
<!-- silent-reason: 只改內部 CI／診斷工作流程的輸出與公開回覆範本字面（不再含學生／老師姓名），教職員看不到差異 -->
- `teacher-signin-recovery`：改用 `--json` 並只重新序列化白名單欄位；解析失敗只印固定訊息。`calendar-course-acceptance`：Playwright 報告以 `DUMP_ARTIFACT_KEY` 加密後才上傳。`bug-phase-c-allowlist` 回覆字面改為「該位學生／老師」；`bug-legacy-production-probes` 改用 `student_id` 輸入。
