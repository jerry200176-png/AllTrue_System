# Request: Phase-C allowlist resolve

Trigger: `bug-phase-c-allowlist.yml`  
Allowlist: in-app **#211** (PR #1528 / merge `c64806f` / deploy run `30516032349`, success 2026-07-30T05:14:45Z) — root cause confirmed (blocking message never told directors they could skip the fixed-weekday requirement by manually picking dates on the calendar; capability already existed), UI copy fix merged and deployed; public reply + resolved.  
Also idempotent: **#210**, **#207**, **#205**, **#198** (skip if already resolved/closed).

**#208 now included, owner-approved.** Root cause: `POST /course-packages/{id}/recompute` had no campus-authorization check (any director/admin could recompute any campus's package by guessing its ID) — fixed in PR #1531 (merge `9adc3633d41126263f0025cdadd7a99cea939f4e`, deploy run 30518848492, success 2026-07-30T06:12:41Z). With the endpoint now safely gated, the owner explicitly authorized a narrowly-scoped, precondition-gated production repair for package 124 / members 2824,2825 only (`package-124-guarded-repair.yml` run [30540075390](https://github.com/jerry200176-png/AllTrue_System/actions/runs/30540075390), 2026-07-30T11:50:08Z): all 10 mandatory preconditions passed, `PackageDeductionService::fullRecompute(124)` applied. Result verified by a fresh, independent `bug-detail-dump.yml` re-read (run [30540267743](https://github.com/jerry200176-png/AllTrue_System/actions/runs/30540267743), 2026-07-30T11:53:01Z): package 124 unchanged (total_sessions=56, remaining_sessions=55, used_sessions=1); course 2824 unchanged (RemainingSessions=55, UsedSessions=1); course 2825 RemainingSessions **96 → 55** (UsedSessions unchanged at 0); ledger unchanged (1 row, id 1768, package_id 124, student_class_id 2824, delta -1, reason attendance). Matches the owner's mandated expected result exactly — safe to resolve.

**#224 added.** Root cause confirmed via code inspection (B1, no production data write needed — pure frontend lookup bug): `SmartCalendar.vue::findSessionRowForCell()` required an exact match between a materialized `ClassSession` row's start_time and the course's contract start_time, only falling back to "any same-date row" for reschedule exceptions. Manually-booked sessions (#211 逐堂手動排課) can have a user-chosen start_time that differs from the course default, so the lookup silently returned null for them — hiding the 取消本堂 button and roll-call/eval badges with no error message, matching the reported "cannot move or delete" symptom exactly. Fixed in PR #1673 (merge `da51be6efd769aff696bcb98a7554206ff042c80`, deploy run 31181097163, success); health `{"status":"ok"}` and `version.json` build_sha confirmed matching the merge commit 2026-08-07T13:07Z. GitHub #1671 (triage) / Bug Fix Plan `.cursor/plans/calendar_session_row_lookup_gap_224_2026-08-07.md` / `docs/AI_REGRESSION_LESSONS.md` §R101.

Evidence:
- #208: security fix PR https://github.com/jerry200176-png/AllTrue_System/pull/1531, deploy https://github.com/jerry200176-png/AllTrue_System/actions/runs/30518848492 (success, 2026-07-30T06:12:41Z); guarded repair run https://github.com/jerry200176-png/AllTrue_System/actions/runs/30540075390 (ok:true); post-repair verification dump https://github.com/jerry200176-png/AllTrue_System/actions/runs/30540267743
- #211: PR https://github.com/jerry200176-png/AllTrue_System/pull/1528, deploy https://github.com/jerry200176-png/AllTrue_System/actions/runs/30516032349 (success, 2026-07-30T05:14:45Z)
- #210: issue https://github.com/jerry200176-png/AllTrue_System/issues/1476, merge https://github.com/jerry200176-png/AllTrue_System/pull/1482, deploy https://github.com/jerry200176-png/AllTrue_System/actions/runs/30325356011 (success, 2026-07-28T03:13:56Z)
- #224: issue https://github.com/jerry200176-png/AllTrue_System/issues/1671, merge https://github.com/jerry200176-png/AllTrue_System/pull/1673, deploy https://github.com/jerry200176-png/AllTrue_System/actions/runs/31181097163 (success)
- Health: `{"status":"ok"}` 2026-07-30 / 2026-08-07

# kickoff 2026-07-30T04:20:00Z — Phase C write-back for #210 only; #208 deferred pending recompute evidence

# kickoff 2026-07-30T05:16:00Z — Phase C write-back for #211 (root cause confirmed, UI fix deployed); #208 still deferred pending owner-approved production repair

# kickoff 2026-07-30T11:55:00Z — Phase C write-back for #208: owner-approved guarded repair executed and independently verified, adding to allowlist with exact mandated public reply text

# kickoff 2026-08-07T13:10:00Z — Phase C write-back for #224: findSessionRowForCell same-date fallback fix (PR #1673) deployed and health/version.json-verified; walks new→triaged→in_progress→resolved with public reply since no separate Phase A write-back was performed (workflow_dispatch-only, unavailable to this cloud session)

# kickoff 2026-08-08T04:43:00Z — Phase C write-back for #225/#226/#227: fresh queue/detail dumps verified all three triaged, production revision 84abaddb deployed and health/version/smoke verified; same GitHub issue #1690 and public reply, no data repair
