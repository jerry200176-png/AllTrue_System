## 2026-10-17 — fix(courses): compare weekly slots order-independently in no-added-load (#4098)
<!-- release-notes: silent_ship=silent-2026-10-17-4098-slots-order-independent -->
<!-- silent-reason: Bug fix only — same Mon/Sun slots in a different array order no longer falsely trip capacity; no new UI. -->
- `StudentClassController::update` treats reordered identical weekly slots as unchanged load so a full slot no longer blocks an otherwise identical resubmit.
- Regression: `StudentClassUpdateNoAddedLoadTest::test_reordered_unchanged_multi_slots_save_when_slot_is_full`.
