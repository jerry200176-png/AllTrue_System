# Technical Health Scorecard

- as_of: `2026-09-01T10:40:00Z`
- revision: `9399df8b3cdf3ec6a1839800340f5818f9b7d604`
- scope: read-only monthly engineering health review

| Metric | Current evidence | Interpretation |
|---|---:|---|
| CI failure rate (last 30 completed main runs) | 0.0% (0/30) | completed CI runs only; unavailable is not zero |
| Frontend line coverage trend | 98.5% / delta 0.0 pp | latest two successful CI artifacts |
| PHPStan baseline | 1031 entries / delta -3 | compared with ~30-day git reference |
| Open P1/P2 technical debt | 28 | candidate count; labels do not prove blocker truth |
| Recurrence families | 7 (F1, F2, F3, F4, F5, F7, F6) | registry in `AI_REGRESSION_LESSONS.md` |
| Hot source files (>5 bug-fix commits / 90d) | 41 | production source paths only |

## Roadmap candidates

1. [#544 — [P1] Enable Dependency Review gate when GHAS is available](https://github.com/jerry200176-png/AllTrue_System/issues/544) (priority:p1) — revalidate current evidence, then choose the next bounded payoff.
1. [#868 — [ops] 導入 staging/pre-prod 環境：deploy 改 dev→staging→prod](https://github.com/jerry200176-png/AllTrue_System/issues/868) (priority:p1) — revalidate current evidence, then choose the next bounded payoff.

## Hot files

- `frontend/src/lib/changelogDraft.generated.js` — 93 bug-fix commits
- `frontend/src/lib/staffUpdates.generated.js` — 69 bug-fix commits
- `backend/app/Http/Controllers/StudentClassController.php` — 45 bug-fix commits
- `frontend/src/pages/CourseManagement.vue` — 40 bug-fix commits
- `backend/app/Http/Controllers/ClassSessionController.php` — 31 bug-fix commits
- `frontend/src/lib/releaseNotes.test.js` — 30 bug-fix commits
- `frontend/src/lib/releaseNotes.generated.js` — 20 bug-fix commits
- `frontend/src/pages/DirectorDashboard.vue` — 19 bug-fix commits
- `frontend/src/pages/TuitionCollectionPage.vue` — 16 bug-fix commits
- `frontend/src/pages/SmartCalendar.vue` — 15 bug-fix commits

> Roadmap candidates are not closure evidence. Re-check the issue body, current code, production SHA, and required owner decision before changing any issue status.
