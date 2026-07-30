# Request: single bug detail dump (read-only)

bug_id: 208

Purpose: confirm whether the one-time `POST /course-packages/124/recompute` ops action (needed to fix the specific already-affected record, per PR #1511) has actually run — i.e. whether member course 2825's `RemainingSessions` is still stale (96) or has been corrected (55). Blocks Phase-C resolve for #208 until confirmed.
**No writes.**

# kickoff 2026-07-30T04:40:00Z — retry after fixing missing `<?php` tag bug (generated probe file was being echoed as raw text by `require`, not executed — same defect affected the original #207 dump on 2026-07-22, confirmed via job logs, not introduced by this change)
