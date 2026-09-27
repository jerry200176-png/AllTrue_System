# AllTrue Autonomous Execution Harness

Orchestration only. Governance authority: `scripts/governance/autonomy_gate.py`.

```bash
python3 -m scripts.harness sync --refresh-tasks
python3 -m scripts.harness status --sync
python3 -m scripts.harness resume --sync
python3 -m scripts.harness founder-inbox
```

Docs: `docs/harness/HARNESS_STATUS.md`
