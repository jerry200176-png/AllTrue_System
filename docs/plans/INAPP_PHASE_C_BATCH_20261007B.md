# Scoped Phase-C batch 2026-10-07 B (in-app 333)

Combined per Founder rule 1A. Existing single-target writer; no gate, actor,
permission, evidence or writer change. Mechanically R3/T3; standing GO B.

| In-app | Issue | Basis | rev (merge) | Deploy run |
|---|---|---|---|---|
| 333 | #3138 | #3500 per-teacher slot groups (EnrollmentService filters by subject and teacher; feature test). Close as explained for existing data (see below). | `f3aa9efca6b235d0ddf049a29a0fd396eea1bd97` | 37563232975 (contains the rev) |

Named review of the `C_past_or_artifact` rows (coordinator jerry-d7, 2026-10-07):
read-only probe run 37571711459 (`inapp_333_multi_teacher_templates`) found one
contaminated contract, course 448 (stopped, campus 16), with sessions 3843 and
3844 (completed) and 3846 (attended). Each has a learning record and a ledger
deduction, so the lessons were really taught and the charges stand. Decision:
no data change. The reply says so in plain words.

Reply template rule (this PR): every new reply offers both 「確認已修好」 and
「問題仍存在」; `bug-writeback-workflow.test.mjs` enforces it for every entry
not in the frozen pre-rule list (already sent; not re-sent by decision).

Dispatch via `bug_id` after merge; this PR dispatches nothing.
Recovery after dispatch: the reporter's 「問題仍存在」 reopen path. If the evidence
is found wrong first: resolved → in_progress via the admin status API with a
note, a correction comment through bug-followup-comment.yml, and
`gh issue reopen`. Append-only evidence stays as audit history.
