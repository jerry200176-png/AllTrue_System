## 2026-11-01 — refactor(frontend): split the export modal out of LearningRecordsPage
<!-- release-notes: silent_ship=silent-2026-11-01-lr-split-export-modal -->
<!-- silent-reason: internal refactor, moves the export-cards modal into its own component with no behavior, copy or style change (#4659) -->
- The 匯出評量圖 modal now lives in `components/learning-records/LearningRecordExportModal.vue`. Same markup, same export flow, same styles.
