# Request: Phase-C allowlist resolve

Trigger: `bug-phase-c-allowlist.yml`  
Allowlist: in-app **#211** (PR #1528 / merge `c64806f` / deploy run `30516032349`, success 2026-07-30T05:14:45Z) — root cause confirmed (blocking message never told directors they could skip the fixed-weekday requirement by manually picking dates on the calendar; capability already existed), UI copy fix merged and deployed; public reply + resolved.  
Also idempotent: **#210**, **#207**, **#205**, **#198** (skip if already resolved/closed).

**#208 is intentionally NOT in this allowlist.** Confirmed via `bug-detail-dump.yml` (run 30515306009/30515460466, 2026-07-30) that member course 2825's `RemainingSessions` is still stale (96, unchanged since 2026-07-24) despite the package level and sibling course 2824 being correct. Root cause investigated (read-only): the fix requires calling the existing `/course-packages/124/recompute` action, which is a production data-mutation action requiring explicit human approval before execution — not something this automation may do on its own. Do not mark #208 resolved or claim "already corrected" until that action has been run and re-verified.

Evidence:
- #211: PR https://github.com/jerry200176-png/AllTrue_System/pull/1528, deploy https://github.com/jerry200176-png/AllTrue_System/actions/runs/30516032349 (success, 2026-07-30T05:14:45Z)
- #210: issue https://github.com/jerry200176-png/AllTrue_System/issues/1476, merge https://github.com/jerry200176-png/AllTrue_System/pull/1482, deploy https://github.com/jerry200176-png/AllTrue_System/actions/runs/30325356011 (success, 2026-07-28T03:13:56Z)
- Health: `{"status":"ok"}` 2026-07-30

# kickoff 2026-07-30T04:20:00Z — Phase C write-back for #210 only; #208 deferred pending recompute evidence

# kickoff 2026-07-30T05:16:00Z — Phase C write-back for #211 (root cause confirmed, UI fix deployed); #208 still deferred pending owner-approved production repair
