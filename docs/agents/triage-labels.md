# Triage Labels

The skills speak in terms of five canonical triage roles. This file maps those roles to the actual label strings used in this repo's issue tracker.

| Label in mattpocock/skills | Label in our tracker | Meaning                                  |
| -------------------------- | -------------------- | ---------------------------------------- |
| `needs-triage`             | `status:triage`         | Maintainer needs to evaluate this issue  |
| `needs-info`               | `status:blocked`        | Waiting on information or a prerequisite |
| `ready-for-agent`          | `status:ready`          | Fully specified, ready for an AFK agent  |
| `ready-for-human`          | `needs-founder` + `status:needs-decision` | Needs a Founder decision or action |
| `wontfix`                  | close as "not planned"  | Will not be actioned                     |

When a skill mentions a role (e.g. "apply the AFK-ready triage label"), use the corresponding label string from this table.

Label names are defined only in [`.github/labels.yml`](../../.github/labels.yml).

## Root-cause family (in-app issues)

Every in-app issue gets exactly one `rootfam:*` label at triage (by mechanism, not by screen). Definitions: [#4991 comment](https://github.com/jerry200176-png/AllTrue_System/issues/4991#issuecomment-6817263700); label list: `.github/labels.yml`. Backfill: `scripts/ci/rootfam-backfill.mjs` (dry-run by default).
