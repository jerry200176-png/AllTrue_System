# Leave-cascade slot repair — Director CSV review pack

**Issue:** [#1342](https://github.com/jerry200176-png/AllTrue_System/issues/1342)  
**Closeout:** [`docs/incidents/leave-cascade-slot-times-closeout-2026-07-19.md`](../incidents/leave-cascade-slot-times-closeout-2026-07-19.md)  
**Command:** `php artisan repair:leave-cascade-slot-times`

## Non-goals

- No batch `--execute --force` on the full candidate set.
- No large UI in this track.
- No Founder/engineer raw-DB triage as the primary path.

## Produce the review pack (ops / Actions)

1. Dry-run + CSV (selected defaults to `0`):

```bash
php artisan repair:leave-cascade-slot-times --dry-run --limit=200 \
  --export-csv=/tmp/leave-slot-review-all.csv
```

2. Keep **high_confidence** rows for the first director queue (offline filter on column `confidence`).

3. Actions workflow `ops-portfolio-td059-leave-audit.yml` uploads a **redacted** HC CSV artifact (student names removed). Full named CSV stays on Pi `/tmp` for the director who will approve.

## Director columns (meaning)

| Column | Meaning |
|--------|---------|
| selected | Always `0` until director marks rows to apply |
| confidence | `high_confidence` / `medium_pattern` / `needs_review` |
| class_session_id | Session to remap (allowlist id) |
| current_* | Wrong clock currently on that date |
| contract_* | Target clock from that weekday’s contract slot |
| classify_reason | Why HC (leave row and/or leave sibling) |
| status | Session status (`leave` / `scheduled` / …) |

## Approval → execute (only after explicit allowlist)

Director returns approved `class_session_id` list. Ops runs **only**:

```bash
ALLOW_PROD_REPAIR=1 php artisan repair:leave-cascade-slot-times \
  --execute --force \
  --session-ids=ID1,ID2,... \
  --snapshot=/tmp/leave-slot-snapshot.json
```

Without `--session-ids`, execute is rejected.

## Exit gate

- HC pack reviewed; medium/needs_review not batch-applied.
- Snapshot retained; remaps idempotent on re-run.
- Public/teacher follow-up only if a named case remains wrong after repair.
