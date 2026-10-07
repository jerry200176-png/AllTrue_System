# Scoped Phase-C batch 2026-10-07 A (in-app 338)

Combined per Founder rule 1A (one paperwork PR per batch). Existing single-target
writer; no gate, actor, permission, evidence or writer change. Mechanically R3/T3.
Both revs are contained in production deploy run 37563232975 (success, head
`1d8b893e`, `git merge-base --is-ancestor`). Evidence level: engineering tests
plus production version check; no production UI check; reporter acceptance pending.

| In-app | Issue | Basis | rev (merge) | Deploy run |
|---|---|---|---|---|
| 338 | #3148 | #3499 occupancy uses ScheduleGuardService live-row rules + #3424 used-up count courses free template seats (AvailabilityCapacityTest). Replaces #3710 (closed on conflict). | `59daf9a39f1b66d6b6063f185f203955ec882a8f` | 37563232975 |

Deferred: in-app 333 (#3138). The read-only probe (run 37571711459) found 3 past
foreign-slot sessions on stopped course 448 (3843, 3844, 3846), each attended or completed
and deducted. Per the repair plan, `C_past_or_artifact` rows need a named director review of
each charge before the report is closed, so 333 waits for that decision.

Authority and evidence: standing Founder GO B (2026-10-07) covers Phase-C for fixes that are
deployed and have evidence attached. The reply states the evidence level (tests plus version
check, no production UI check) and asks the reporter to confirm with 「確認已修好」 or
「問題仍存在」. Reporter verification is the acceptance step.

Dispatch one ID at a time via `bug_id` after merge; this PR dispatches nothing.

Recovery after dispatch (reverting this PR does not undo writes):
1. If the reporter presses 「問題仍存在」, the existing reopen path returns the report to
   triaged/in_progress.
2. If the evidence or mapping is found wrong before the reporter acts: move the report back
   via the admin status API (resolved → in_progress) with a note citing this PR, post a
   correction comment through bug-followup-comment.yml, and reopen the GitHub issue that
   Phase-C closed (`gh issue reopen <n>`).
3. Append-only evidence rows stay as audit history; the correction note supersedes them.
