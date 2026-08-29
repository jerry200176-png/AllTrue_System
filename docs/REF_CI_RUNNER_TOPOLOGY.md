# CI Runner Topology Reference

## Current contract

All directly executed workflow jobs use `ubuntu-latest` GitHub-hosted runners. This includes CI, presubmit, security scans, documentation checks, deployment, and production health workflows.

The only delegated job is `osv-scanner.yml` → Google's OSV reusable workflow, pinned to immutable commit `6e4298ebc4db23e847df9b2e2de2939d6f066c67` (v2.5.1). The runner is owned by that reviewed reusable workflow, so the exact reference is allow-listed rather than treated as an untracked omission.

The WSL2 self-hosted runner topology introduced by #867 is historical and is not used by any active workflow. Commit `e3b30511` moved the workflows back to GitHub-hosted runners on 2026-07-14; commit `9fbd8038` added the MySQL 8 service required by PHPUnit.

## Reliability and security consequences

- Every PHPUnit job receives its own runner and MySQL service container. The schema name remains `AllTrue_test`, but storage is isolated per job, so concurrent runs cannot drop or migrate another run's database.
- Production deploy credentials stay on ephemeral GitHub-hosted runners. They are not installed on a personal WSL2 runner or the production Pi.
- The production Pi must never be registered as a test runner or execute PHPUnit.
- A runner-topology change is a security and operations boundary change. Update this reference and the operations runbook in the same PR.

## Enforcement

`node scripts/runner-topology-check.mjs` inventories every declared job and fails when a directly executed job does not use `ubuntu-latest` or a delegated job does not match the exact reviewed reusable-workflow allow-list. Presubmit runs the check on every PR.

New or changed job-level reusable workflow calls are rejected by default because their runner and credential boundary is delegated; adopting one requires the same explicit security/operations review and contract update as any other runner change.

If a future workload genuinely requires another runner, the change must document credential exposure, database isolation, runner ownership, capacity, patching, and rollback before modifying the allow-list.
