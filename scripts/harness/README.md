# AllTrue Autonomous Execution Harness

Orchestration only. Governance authority: `scripts/governance/autonomy_gate.py`.

```bash
python3 -m scripts.harness sync --refresh-tasks
python3 -m scripts.harness status --sync
python3 -m scripts.harness resume --sync
python3 -m scripts.harness founder-inbox
```

Docs: `docs/harness/HARNESS_STATUS.md`

`status --json` is a read-only, single-object snapshot unless `--sync` is
explicitly selected. An independent observer can consume `attention_key` once
per worker/state and distinguish lost ownership, deferred launch and progress
not observed for 60 minutes (`--stale-after-minutes`). A recorded external wait
still needs a live PR or run read. This does not observe unmanaged Codex threads.
`resume --checkpoint <ID>` reads an existing recovery point without reclaiming
leases. Recheck the current worker, exact head and existing effect receipts before
restoring a write owner.

Except for explicit `sync` initialization, CLI commands require an existing
`--db` / `HARNESS_DB` (otherwise the host-local default). Missing/inaccessible
state or an uninitialized/unsupported schema returns exit 2 with
`harness_store_unavailable`; it is not an empty queue. These commands do not
initialize or migrate a store; historical schemas use the existing explicit
`migrate-schema` procedure (copy verification before any authorized live step).
Locate the authorized store before resuming; syncing YAML cannot recover its
operational history. Cloud sessions can continue independent work via GitHub.
