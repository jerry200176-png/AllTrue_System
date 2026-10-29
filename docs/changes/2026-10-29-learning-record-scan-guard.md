## 2026-10-29 — test(learning): the DirectorID source-scan guard allows only validation rules and comments (#4443)
<!-- release-notes: silent_ship=silent-2026-10-29-learning-record-scan-guard -->
<!-- silent-reason: Test-only change; no production behaviour. -->
- The guard in `LearningRecordReviewerFromLoginTest` now fails on any `DirectorID` mention in `LearningRecordController` other than a `nullable|integer` rule or a comment (#4443).
