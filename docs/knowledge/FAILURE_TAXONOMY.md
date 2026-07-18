# Failure Taxonomy

| ID | Class | Example | Default control |
|----|-------|---------|-----------------|
| F-DATA | Data integrity / dual rows | Renewal dual-track, duplicate sessions | Code guard + audit |
| F-ATOMIC | Partial multi-write | Legacy two-phase reschedule | Single transaction / R71 |
| F-STALE | Stale projection | schedules scheduled after ClassSession cancel | R72 filter |
| F-AUTHZ | Auth / campus boundary | Wrong campus write | Middleware + tests |
| F-DEPLOY | Deploy/SHA drift | Success with stale hash | version.json gate |
| F-OBS | Silent ops failure | Scheduler evidence incomplete | Pi health / #1127 |
| F-METRIC | Metric gaming | Close issues without verify | Evidence Contract |
| F-AGENT | Agent process failure | Wrong worktree, assumed perms | Preflight + Capability Registry |
