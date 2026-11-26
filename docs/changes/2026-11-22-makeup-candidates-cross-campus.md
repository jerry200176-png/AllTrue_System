## 2026-11-22 — fix(exceptions): makeup time candidates skip times the teacher teaches at another campus (#5588)
<!-- release-notes: silent_ship=silent-2026-11-22-makeup-candidates-cross-campus -->
<!-- silent-reason: 補課建議時段不再列出老師在其他分校已有課的時間；原本這類時段在確認時就會被擋下，畫面與流程不變。 -->

- `ExceptionWorkflowCandidateGenerator::generate`：挑出要顯示的建議時段時，用既有的 `ScheduleGuardService::crossCampusConflict` 排除老師在其他分校的課；只檢查實際提供的幾個時段，不逐格查詢。
- 測試：`CrossCampusWritePathsTest::test_makeup_candidates_skip_times_taught_at_another_campus`。
