# Manual Review Checklist — 2 Flagged Cross-Family LINE Identities (#1401)

**For a human with legitimate production access only.** This document
contains no PII and no query result — it is instructions for the Founder
(or a designated reviewer with production DB access) to look up and
interpret the 2 identities the impact audit flagged
(`line_identities_spanning_multiple_families_all_time = 2`,
`..._verified_only = 0`). This session never had and never sought access
to the underlying values.

## What triggered this review

The impact audit (`audit:1401-impact`, run 2026-07-25) found 2 LINE
identities whose bound students, across all-time bindings (verified +
unverified), have different registered contact-phone hashes — i.e., they
look like they span more than one family. Among **currently verified**
bindings the count is 0, meaning the live containment (PR #1400) has not
let any such pairing become trusted since the fix.

## Why this needs human judgment, not another automated pass

The detection method (hash of registered contact phone) cannot distinguish
two real scenarios:

1. **Benign**: one real family, same parent, whose two children have two
   *different* registered contact phone numbers on file (e.g. mom's number
   for one child, dad's for the other, or a stale/typo'd number on one
   record) — this would look like "2 families" by phone-hash alone even
   though it's one family with a data-entry inconsistency.
2. **The actual concern**: an attacker's LINE identity genuinely bound to
   students from two unrelated families, via the pre-fix vulnerability.

Only a human looking at the actual student/family records (not just phone
hashes) can tell these apart.

## Query to reproduce and inspect the 2 flagged identities (run on production, via `php artisan tinker`)

```php
// Read-only. Reproduces the audit's own grouping logic, but at full detail
// for someone authorized to see it. Run this yourself -- do not paste its
// output back into any AI session, chat, ticket, or file in this repo.
$byLine = \App\Models\StudentLineBinding::query()
    ->select('line_user_id', 'student_id', 'verified_at', 'bound_at')
    ->get()
    ->groupBy('line_user_id');

foreach ($byLine as $lineUserId => $bindings) {
    $students = \App\Models\Student::whereIn('id', $bindings->pluck('student_id')->unique())
        ->get(['id', 'name', 'parent_phone', 'Phone', 'CampusID']);
    $phones = $students->map(fn($s) => \App\Support\StudentContactPhone::normalizedDigits($s))->unique();
    if ($phones->count() > 1) {
        echo "--- flagged identity ---\n";
        foreach ($students as $s) {
            echo "  student_id={$s->id} campus={$s->CampusID}\n";
        }
        foreach ($bindings as $b) {
            echo "  binding: student_id={$b->student_id} verified_at=" . ($b->verified_at ?? 'NULL') . " bound_at={$b->bound_at}\n";
        }
    }
}
```

This prints real PII to your own terminal only — never paste this output
anywhere outside your own secure notes.

## Decision tree for each of the 2 flagged identities

- [ ] **Same campus, plausible sibling names/enrollment pattern, one parent
      contact clearly stale/alternate** → benign data-entry variance.
      Record as "reviewed — benign" (see below), no further action.
- [ ] **Different campuses, no plausible family relationship, or binding
      timestamps/pattern suggest guessing** → treat as a **confirmed
      cross-family exposure instance**. Escalate to:
      - the notification-decision process (closure packet §8)
      - consider whether this specific binding's history needs deeper
        investigation (was data ever accessed/pushed to it — cross-check
        manually against `feedback_push_log`/reminder logs for that
        `student_id`, understanding those logs lack binding granularity
        per the impact audit's own documented limitation)
- [ ] **Unable to determine** → record as "reviewed — inconclusive," and
      note it in the closure packet's impact-audit interpretation as a
      residual open question, not resolved either way.

## Recording the outcome (without PII)

In `docs/incidents/1401-closure-packet.md` §6, replace the "2 flagged,
unresolved" language with one of:

- "2/2 reviewed: benign data-entry variance, no cross-family exposure
  confirmed."
- "N/2 reviewed: confirmed cross-family exposure instance(s); see private
  evidence retention location for detail (never this repo)."
- "2/2 reviewed: inconclusive; treated as unresolved residual risk."

Do **not** paste the query output, student IDs, names, or phone numbers
into this repository, this checklist, or any AI session under any
circumstance.
