# AllTrue Autonomous Execution Harness — Status

| Field | Value |
|-------|--------|
| Updated | 2026-09-17T08:55:00Z |
| Branch | `chore/task-harness-store-authority-cutover` |
| Slice | **HARNESS_STORE_AUTHORITY_CUTOVER** |
| Landed prior | H0–H1 #2977; H2 #3007; H2.1 #3009; H3 #3014; H4 #3022; H4b #3025 |
| Durable DB | schema **v4** live; **domain_authority** |
| CURRENT_STATE | **projection only** (`python3 -m scripts.harness project-state`) |
| Founder | operational acceptance pending; machine restart UNPROVEN |

| Slice | Status |
|-------|--------|
| H0–H4b | MERGED on main |
| Store authority cutover | CODE+LIVE+DOGFOOD verified; OPERATIONALLY_ACCEPTED pending |
| H5+ / Restate Gate-1 | backlog — do not start automatically |

```bash
python3 scripts/tests/test_harness_cutover.py
python3 -m scripts.harness migrate-schema --src <db> --copy <copy.sqlite>
python3 -m scripts.harness migrate-schema --src <db> --live --i-understand-live
python3 -m scripts.harness project-state
python3 -m scripts.harness status
```

See `docs/harness/CUTOVER_LIFECYCLE.md` and `docs/harness/CUTOVER_ROLLBACK.md`.
