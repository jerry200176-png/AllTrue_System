# CI Runner Topology Reference

## Current contract

All active workflow jobs use `ubuntu-latest` GitHub-hosted runners. This includes CI, presubmit, security scans, documentation checks, deployment, and production health workflows.

The WSL2 self-hosted runner topology introduced by #867 is historical and is not used by any active workflow. Commit `e3b30511` moved the workflows back to GitHub-hosted runners on 2026-07-14; commit `9fbd8038` added the MySQL 8 service required by PHPUnit.

## Reliability and security consequences

- Every PHPUnit job receives its own runner and MySQL service container. The schema name remains `AllTrue_test`, but storage is isolated per job, so concurrent runs cannot drop or migrate another run's database.
- Production deploy credentials stay on ephemeral GitHub-hosted runners. They are not installed on a personal WSL2 runner or the production Pi.
- The production Pi must never be registered as a test runner or execute PHPUnit.
- A runner-topology change is a security and operations boundary change. Update this reference and the operations runbook in the same PR.

## Enforcement

`node scripts/runner-topology-check.mjs` fails when an active workflow job does not use `ubuntu-latest`. Presubmit runs the check on every PR.

If a future workload genuinely requires another runner, the change must document credential exposure, database isolation, runner ownership, capacity, patching, and rollback before modifying the allow-list.
