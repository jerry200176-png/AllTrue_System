## 2026-10-24 — feat(billing): bounded ordinary-close activity census
<!-- release-notes: silent_ship=silent-2026-10-24-ordinary-close-activity-census -->
<!-- silent-reason: internal read-only evidence helper and synthetic database tests only; no existing close path is wired -->
- Adds an unwired, transaction-bound census across attendance, learning, schedule, correction, group and successor histories; it returns owner-bound IDs and keyed digests while keeping raw row text transient.
