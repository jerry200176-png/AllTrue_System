---
type: silent_ship
silent_reason: internal in-app lifecycle control plane; no user-facing behavior change until the Founder authorizes the new production write and a resolve is dispatched
---
Add a guarded Phase-C resolve path (`bug-phase-c-resolve.yml` + `inapp-writeback-rows.py` `phase-c` kind) so a deployed, verified in-app bug can move triaged/in_progress → resolved with mandatory production evidence and a both-outcomes reply. Held for a Founder decision on the new production write authority.
