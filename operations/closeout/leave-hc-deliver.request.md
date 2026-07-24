# Deliver #1342 campus CSV via existing director LINE DM / internal group

**Owner:** platform-ops  
**Authorized:** 2026-07-22 (Founder) — do NOT wait for Gmail SMTP; do NOT build new notify integration.

## Steps (each campus)

1. Download artifact `director-leave-hc-and-evidence` from Actions run `29686172773`.
2. Pick campus CSV; verify sha256 vs `operations/closeout/leave-hc-campus-review-tracker.json`.
3. Send via **existing approved** director LINE DM or internal group (no GitHub, no public CSV).
4. Backfill tracker: `channel`, `csv_sha256`, `delivered_at`, `next_track_at` → status stays pre-ack until receipt confirmed.
5. On director confirmation of receipt: set `acknowledged_at`, status=`awaiting_review`.
6. If still no `acknowledged_at` after **2026-07-22 18:00+08** → `deadline_at_risk` (ack risk ≠ content non-review).

Forbidden on GitHub: director real names, student data, named CSV bodies.

# manual-dm-authorized 2026-07-22T03:25:00Z
