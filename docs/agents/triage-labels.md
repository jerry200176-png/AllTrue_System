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
