# Request: single bug detail dump (read-only)

bug_id: 208

Purpose: confirm whether the one-time `POST /course-packages/124/recompute` ops action (needed to fix the specific already-affected record, per PR #1511) has actually run — i.e. whether member course 2825's `RemainingSessions` is still stale (96) or has been corrected (55). Blocks Phase-C resolve for #208 until confirmed.
**No writes.**

# kickoff 2026-07-30T04:48:00Z — retry after fixing StudentClass campus lookup (StudentClass has no CampusID column; campus lives on Student, joined via StudentID — query previously selected the nonexistent `sc.CampusID`)
