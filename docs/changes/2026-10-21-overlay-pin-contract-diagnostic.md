## 2026-10-21 — explain the shared overlay contract failure
<!-- release-notes: silent_ship -->

- The legacy overlay checker now explains the sunrise-cafe #470 rulebook migration instead of exiting silently on an empty version match, and removes its temporary download on exit.
- Legacy version equality, drift rejection and malformed-input rejection remain enforced. This change does not migrate AllTrue governance or make the migrated upstream contract pass.
- Four offline regression tests exercise the actual shell gate. Rollback: revert this diagnostic-only change; application runtime and data are unaffected.
