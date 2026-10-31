## 2026-11-01 — feat(attendance): check-in says when a shared lesson plan is used up
<!-- release-notes: staff_update=staff-2026-11-01-shared-pool-checkin-notice -->
- `POST /api/v1/attendance` (and batch mark, which reuses it) returns `package_notice` after a deducting check-in on a shared count plan: 「共用方案堂數已用完，請安排續課。」 on the last bought lesson, 「共用方案已超出 N 堂，請先續課。」 after it. Check-in is never blocked (Founder 2026-11-01, choice A). The attendance page appends the notice to its success message.
