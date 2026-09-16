# AllTrue Autonomous Execution Harness

Orchestration layer for AllTrue programs. **Does not replace governance.**

Authority for risk/activation: `scripts/governance/autonomy_gate.py`.

## Commands

```bash
python3 -m scripts.harness sync --refresh-tasks
python3 -m scripts.harness status --sync
python3 -m scripts.harness plan --program truefit --sync
python3 -m scripts.harness resume --sync
python3 -m scripts.harness founder-inbox
```

Durable DB default: `/home/jerry/workspace/state/alltrue/harness.sqlite`  
Override: `HARNESS_DB=/tmp/harness-test.sqlite`

## Docs

- `docs/harness/HARNESS_STATUS.md` — resume SSOT
- `docs/harness/CAPABILITY_MAP.yaml` — H0 map
- `docs/harness/ARCHITECTURE_V1.md` — architecture
