## 2026-11-01 — chore(ops): POP operation to cancel forward-generator extras on fully used courses
<!-- release-notes: silent_ship=silent-2026-11-01-forward-extra-cancel-pop -->
<!-- silent-reason: reviewed ops data-repair operation; no screen change -->
- New POP operation `forward-extra-cancel-20261101` (#4758, in-app #452) with adapter `pop-forward-extra-cancel.yml`. It cancels the `scheduled` lessons the nightly forward generator added to courses whose bought lessons are all used, when nothing points at them and they are today or later (or duplicated on a later course of the same student and subject). Other such lessons are only listed for a director. The forward-overdeduct probe and the operation now share one scan (`ForwardSessionExtras`). Runbook `RM-FORWARD-EXTRA-CANCEL-20261101`.
