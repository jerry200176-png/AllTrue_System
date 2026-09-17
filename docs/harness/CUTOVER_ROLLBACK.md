# HARNESS_STORE_AUTHORITY_CUTOVER — Rollback

## Snapshots (restorable)

| Snapshot | Path |
|----------|------|
| Pre-cutover inventory backup | `/home/jerry/workspace/state/alltrue/harness-backups/harness.sqlite.pre-cutover-20260917T084845Z` |
| Pre-live-migrate backup | `/home/jerry/workspace/state/alltrue/harness-backups/harness.sqlite.pre-live-migrate-20260917T085431Z` |
| CURRENT_STATE pre-projection | `/home/jerry/workspace/state/alltrue/CURRENT_STATE.json.pre-projection-*` |

## Rollback steps (if authority ambiguous)

1. **STOP** all harness writers (`python3 -m scripts.harness …`, dogfood supervisor).
2. Do **not** delete evidence JSON or WorkerRun rows.
3. Restore DB from pre-live backup:

```bash
LIVE=/home/jerry/workspace/state/alltrue/harness.sqlite
SNAP=/home/jerry/workspace/state/alltrue/harness-backups/harness.sqlite.pre-live-migrate-20260917T085431Z
sqlite3 "$SNAP" ".backup '$LIVE'"
sqlite3 "$LIVE" "PRAGMA integrity_check;"
sqlite3 "$LIVE" "SELECT value FROM meta WHERE key='schema_version';"  # expect 1
```

4. Restore `CURRENT_STATE.json` from the newest `*.pre-projection-*` backup if needed.
5. Clear cutover meta only after restore (optional): leave evidence files under `/home/jerry/workspace/state/alltrue/cutover-evidence-20260917/`.

## When to rollback

- Projection diverges from sqlite domain rows
- Restore cannot be proven
- Duplicate authoritative writers reappear (JSON treated as SoT again)
- Integrity check fails
