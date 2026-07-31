# Request: Phase-C allowlist resolve

Trigger: `bug-phase-c-allowlist.yml`  
Allowlist: in-app **#211** (PR #1528 / merge `c64806f` / deploy run `30516032349`, success 2026-07-30T05:14:45Z) — root cause confirmed (blocking message never told directors they could skip the fixed-weekday requirement by manually picking dates on the calendar; capability already existed), UI copy fix merged and deployed; public reply + resolved.  
Also idempotent: **#210**, **#207**, **#205**, **#198** (skip if already resolved/closed).

**#208 now included, owner-approved.** Root cause: `POST /course-packages/{id}/recompute` had no campus-authorization check (any director/admin could recompute any campus's package by guessing its ID) — fixed in PR #1531 (merge `9adc3633d41126263f0025cdadd7a99cea939f4e`, deploy run 30518848492, success 2026-07-30T06:12:41Z). With the endpoint now safely gated, the owner explicitly authorized a narrowly-scoped, precondition-gated production repair for package 124 / members 2824,2825 only (`package-124-guarded-repair.yml` run [30540075390](https://github.com/jerry200176-png/AllTrue_System/actions/runs/30540075390), 2026-07-30T11:50:08Z): all 10 mandatory preconditions passed, `PackageDeductionService::fullRecompute(124)` applied. Result verified by a fresh, independent `bug-detail-dump.yml` re-read (run [30540267743](https://github.com/jerry200176-png/AllTrue_System/actions/runs/30540267743), 2026-07-30T11:53:01Z): package 124 unchanged (total_sessions=56, remaining_sessions=55, used_sessions=1); course 2824 unchanged (RemainingSessions=55, UsedSessions=1); course 2825 RemainingSessions **96 → 55** (UsedSessions unchanged at 0); ledger unchanged (1 row, id 1768, package_id 124, student_class_id 2824, delta -1, reason attendance). Matches the owner's mandated expected result exactly — safe to resolve.

Evidence:
- #208: security fix PR https://github.com/jerry200176-png/AllTrue_System/pull/1531, deploy https://github.com/jerry200176-png/AllTrue_System/actions/runs/30518848492 (success, 2026-07-30T06:12:41Z); guarded repair run https://github.com/jerry200176-png/AllTrue_System/actions/runs/30540075390 (ok:true); post-repair verification dump https://github.com/jerry200176-png/AllTrue_System/actions/runs/30540267743
- #211: PR https://github.com/jerry200176-png/AllTrue_System/pull/1528, deploy https://github.com/jerry200176-png/AllTrue_System/actions/runs/30516032349 (success, 2026-07-30T05:14:45Z)
- #210: issue https://github.com/jerry200176-png/AllTrue_System/issues/1476, merge https://github.com/jerry200176-png/AllTrue_System/pull/1482, deploy https://github.com/jerry200176-png/AllTrue_System/actions/runs/30325356011 (success, 2026-07-28T03:13:56Z)
- Health: `{"status":"ok"}` 2026-07-30

# kickoff 2026-07-30T04:20:00Z — Phase C write-back for #210 only; #208 deferred pending recompute evidence

# kickoff 2026-07-30T05:16:00Z — Phase C write-back for #211 (root cause confirmed, UI fix deployed); #208 still deferred pending owner-approved production repair

# kickoff 2026-07-30T11:55:00Z — Phase C write-back for #208: owner-approved guarded repair executed and independently verified, adding to allowlist with exact mandated public reply text
