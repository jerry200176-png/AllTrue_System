## 2026-10-06 — chore(ops): TD-076 R-1 collision keeper falls back to the effective substitute when no LearningRecord exists (dry-run only)
<!-- release-notes: silent_ship=silent-2026-10-06-td076-r1-keeper-resolver -->
<!-- silent-reason: 只調整尚未對正式站執行的 POP dry-run 保留規則與測試，教職員看不到差異 -->
- POP 操作 `td076-r1-collision-keepers-20261006`：同一堂次沒有未作廢的 LearningRecord 時，改依行事曆同一規則（最新的有效代課列：教師不同於合約教師且有 `original_schedule_id`）決定保留哪一筆；有 LearningRecord 但不一致、或仍無法判斷時照舊隔離。`mixed_status`、`multiple_sessions` 規則不變。僅 dry-run。Refs #3590
