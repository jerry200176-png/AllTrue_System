# Repair Manifest — TD-076 Track B occurrence repairs 2026-10-06

Status: POP operations `td076-r1-collision-keepers-20261006` (R-1) and, in the stacked PR, `td076-r2-history-pins-20261006` (R-2).
Risk: R3/T3. Design: `docs/architecture/DESIGN_TD076_TRACK_B_SUBSTITUTE.md` §0 (D2-D4), §4, §5, §7. Refs #3590 item 2.
This PR is dry-run only: nothing has been executed against production.

## R-1 collision keepers

Scope: one campus (`campus_id`; pilot = CampusID 11). Groups = live rows (`scheduled`/`leave`, never `extra`) that share a frozen
identity or a current slot (merged transitively). Keeper = the row on the live ClassSession slot; if several, the newest substitute row
whose `teacher_id` is the session's non-voided LearningRecord teacher. Losers move to `schedules.status='superseded'` with a
`schedule_change_log` row (`reason=repair_supersede`, from/to status and teacher). Quarantined and untouched: `no_session`,
`multiple_sessions`, `no_slot_match`, `teacher_conflict`, `mixed_status`, `identity_anchor_mismatch` (#3590 item 2: frozen identity differs from the anchor's slot).
Inverse (rollback): superseded rows go back to their old status, plus another log row.

## R-2 history pins

Scope: taught past occurrences (the #207 set, dated before today) with no exception row (live non-makeup row with an anchor), not leave, not makeup,
where the evidence teacher (non-voided LearningRecord, manual and RFID sign-ins) is not `StudentClass.TeacherID`. Action: `OccurrenceAssignmentService::pinTaughtTeacher`
(identity stamped, `reason=pin`). If the sources name more than one teacher (D2: do not guess), the occurrence is quarantined as `evidence_conflict` and not pinned.
Inverse: removes the created pin rows, their pin log rows and any anchor the pin created; a pin changed since (substitute/restore log) is skipped and reported.

## Dry-run, digest and execute gates

1. Dry-run: draft with `expected_digest=''`. Writes nothing. Returns `counts`, an ids-only `manifest` (no names) and its `sha256` `digest`.
2. Founder reviews the manifest, then drafts again with `expected_digest=<digest>`; its dry-run must report `state=pinned`.
3. Execute needs: that exact digest (execute recomputes the manifest under the transaction and aborts on any drift), a `founder-go-*` approval
   reference bound to the deployed SHA, a super_admin approver, and the `production-activation` environment. Policy `founder-exact-td076-occurrence-repair`.
4. After execute the same parameters report `state=after` (no action left); verify requires that.
