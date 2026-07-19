# Credential Fingerprint Audit

This runbook verifies whether credentials found in historical incident commits are still
present in production. It is a read-only production diagnostic and does not rotate,
revoke, deploy, or update data.

## Safety contract

- The workflow downloads only the four GitGuardian-linked blobs into an ephemeral
  GitHub-hosted runner; it does not materialize full historical snapshots.
- Secret values are hashed in memory. Raw values are never printed or uploaded.
- Fingerprints stay in temporary files and are deleted even when the audit fails.
- Logs contain only credential class, candidate counts, and one of:
  `DIFFERENT`, `MATCH_ROTATION_REQUIRED`, or an incomplete-audit status.
- Production SSH host verification uses the pinned `PI_HOST_KEY` repository secret.
- The audit uses the matching read-only operations endpoint identity, `PI_HOST` and
  `PI_USER`; deployment aliases are not mixed with this host-key pin.

## Operation

Run **Credential Fingerprint Audit (read-only)** with `workflow_dispatch`.

| Result | Meaning | Required action |
|---|---|---|
| `DIFFERENT` | No historical candidate matches a current production credential. | Preserve the run as incident evidence. |
| `MATCH_ROTATION_REQUIRED` | At least one exposed credential is still current. | Keep the repository private and rotate through the approved production path. |
| `*_NOT_FOUND` | Extraction or production inventory was incomplete. | Fix the diagnostic before drawing a security conclusion. |

A successful audit proves only that the scanned incident candidates differ from current
production values. Repository history/object cleanup and GitHub cache invalidation remain
separate publication gates.
