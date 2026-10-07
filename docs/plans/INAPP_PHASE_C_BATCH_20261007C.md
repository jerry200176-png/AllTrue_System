# Phase-C batch 2026-10-07 C (in-app 333 close-as-explained) + probe refinement

| In-app | Issue | Basis | rev (merge) | Deploy run |
|---|---|---|---|---|
| 333 | #3138 | #3500 per-teacher slot groups. Existing data: read-only probe run 37571711459 flagged only course 448. The coordinator's read-only SELECT showed Tuesdays 2/24, 3/3 and 3/17, then Thursdays 3/26 to 4/23: a legitimate move with the template updated, not contamination. No data change. | `f3aa9efca6b235d0ddf049a29a0fd396eea1bd97` | 37563232975 (contains the rev) |

The reply offers both 「確認已修好」 and 「問題仍存在」. Standing GO B applies (the fix is deployed and the evidence is attached).

Probe refinement (`inapp_333_multi_teacher_templates`): a template that holds none of the contract's
own early slots means it was edited after start, so it is labelled `review_only:
template_changed_after_start` and its clean future foreign-slot sessions go to `C_review_template_changed`. Past or charged rows keep `C_past_or_artifact`, because ledger risk wins.
The probe never treats this shape as an A/B repair. Verified with a throwaway isolated-MariaDB
test that seeds the course-448 shape.

Dispatch via `bug_id` after merge; this PR dispatches nothing. Recovery: the reopen path; if the
evidence is found wrong, resolved → in_progress, plus a correction comment and an issue reopen.
