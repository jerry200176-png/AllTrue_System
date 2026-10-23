# AllTrue Autonomous Execution Harness

Orchestration only. Governance authority: `scripts/governance/autonomy_gate.py`.

```bash
python3 -m scripts.harness sync --refresh-tasks
python3 -m scripts.harness status --sync
python3 -m scripts.harness resume --sync
python3 -m scripts.harness founder-inbox
```

Docs: `docs/harness/HARNESS_STATUS.md`

Except for explicit `sync` initialization, CLI commands require an existing
`--db` / `HARNESS_DB` (otherwise the host-local default). Missing/inaccessible
state returns exit 2 with `harness_store_unavailable`; it is not an empty queue.
Locate the authorized store before resuming; syncing YAML cannot recover its
operational history. Cloud sessions can continue independent work via GitHub.
