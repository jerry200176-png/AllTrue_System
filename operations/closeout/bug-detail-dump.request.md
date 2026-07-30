# Request: single bug detail dump (read-only)

bug_id: 208

Purpose: confirm whether the one-time `POST /course-packages/124/recompute` ops action (needed to fix the specific already-affected record, per PR #1511) has actually run — i.e. whether member course 2825's `RemainingSessions` is still stale (96) or has been corrected (55). Blocks Phase-C resolve for #208 until confirmed.
**No writes.**

# kickoff 2026-07-30T04:42:00Z — retry after fixing bug_report_attachments column name (query selected `size_bytes`, actual column per migration 2026_04_11_100000_create_bug_report_attachments_table.php is `size`; `<?php` fix landed and confirmed the file now executes, this is the next blocker it revealed)
