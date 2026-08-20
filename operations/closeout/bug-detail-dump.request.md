# Request: single bug detail dump (read-only)

bug_id: 240

Purpose: Follow-up check for already-triaged in-app bug #240 (status=triaged, severity=critical, campus_id=3, page_key=tuition-collect, title "為何帳務中心裡明明已繳款還會出現在應繳", reporter_user_id=72, created 2026-08-19 12:27:06, updated 2026-08-19 22:23:07, no attachments, GitHub issue #1911 already opened but blocked on missing student/course/amount detail) per bug-queue-dump run 32337591208, dump_generated_at 2026-08-20T05:57:30Z. Need full description, comments, status_logs, and any public_reply already posted, to confirm whether the reporter has been asked for more detail yet before re-asking.
**No writes.**

# kickoff 2026-07-30T04:48:00Z — retry after fixing StudentClass campus lookup (StudentClass has no CampusID column; campus lives on Student, joined via StudentID — query previously selected the nonexistent `sc.CampusID`)

# kickoff 2026-07-30T04:56:00Z — retry after fixing StudentClass updated_at (StudentClass has no created_at/updated_at timestamps; only `MDate` — query previously selected the nonexistent `sc.updated_at`)

# kickoff 2026-07-30T05:02:00Z — #208 evidence captured; re-pointing at bug_id 211 for its detail read

# kickoff 2026-07-30T11:52:00Z — post-repair verification for #208, re-pointing bug_id back to 208

# kickoff 2026-08-07T11:52:30Z — Phase A triage for new bug #224 (student class cannot be moved/deleted), re-pointing bug_id to 224; must land within 15 min of bug-queue-dump run 31175636740 (generated 2026-08-07T11:49:48Z) for freshness gate

# kickoff 2026-08-20T05:59:09Z — follow-up on already-triaged #240 (GitHub #1911 blocked on missing reporter detail); re-pointing bug_id to 240; must land within 15 min of bug-queue-dump run 32337591208 (generated 2026-08-20T05:57:30Z) for freshness gate
