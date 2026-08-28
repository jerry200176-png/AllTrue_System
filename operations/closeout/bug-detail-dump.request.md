# Request: single bug detail dump (read-only)

bug_id: 245

Purpose: Phase A triage for new in-app bug #239 (status=new, severity=critical, campus_id=3, page_key=calendar, title "代課老師設定寫成功但實際課表根本無法改", reporter_user_id=72, created 2026-08-18 14:38:33, no attachments per bug-queue-dump run 32210083540, dump_generated_at 2026-08-19T02:52:13Z). Need description, comments, status_logs, and reporter's full history/cross-campus record per CHAT_BUG_SYSTEM.md §3.6 before proposing a root cause.
**No writes.**

# kickoff 2026-08-28T04:45:30Z — fresh queue identifies open in-app #245; obtain complete description, attachments, comments, status logs, and reporter history for Phase A triage

# kickoff 2026-07-30T04:48:00Z — retry after fixing StudentClass campus lookup (StudentClass has no CampusID column; campus lives on Student, joined via StudentID — query previously selected the nonexistent `sc.CampusID`)

# kickoff 2026-07-30T04:56:00Z — retry after fixing StudentClass updated_at (StudentClass has no created_at/updated_at timestamps; only `MDate` — query previously selected the nonexistent `sc.updated_at`)

# kickoff 2026-07-30T05:02:00Z — #208 evidence captured; re-pointing at bug_id 211 for its detail read

# kickoff 2026-07-30T11:52:00Z — post-repair verification for #208, re-pointing bug_id back to 208

# kickoff 2026-08-07T11:52:30Z — Phase A triage for new bug #224 (student class cannot be moved/deleted), re-pointing bug_id to 224; must land within 15 min of bug-queue-dump run 31175636740 (generated 2026-08-07T11:49:48Z) for freshness gate
