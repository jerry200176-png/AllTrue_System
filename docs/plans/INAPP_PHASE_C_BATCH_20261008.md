# Phase-C batch 2026-10-08 (in-app 381, 377, 378)

| In-app | Issue | Basis | rev (merge) | Deploy run |
|---|---|---|---|---|
| 381 | #3799 | #3740: DELETE students/{id}/bind-card plus teacher unbind (F20); route confirmed on the production server read-only. | `f009fce12c6ff63cb1b79d0e3e9014c7d4d6efd8` | 37726665054 (head 13ca9382e, contains the rev) |
| 377 | #3771 | #3730: ended monthly contracts priced by their own month. Existing triage comment already says fixed; a new no-names reply is posted (the old one names a student, so it cannot be copied into this public repo). | `fa3d8eedc3c26881279add1a695a9e846bd4208d` | 37581142543 |
| 378 | #3772 | #3730 + #3733: ended monthly contract is findable and the bill shows the real start/end range. | `96e97505aebf9abfc85c19ad45d681b7cf56fdc7` | 37607736238 |

In-app 384 (#3774) needs no entry: it is already `closed` in-app with the answer posted (comment 918); the GitHub issue is closed by hand.
Standing GO B applies (fix deployed, evidence attached). Dispatch is by #3754 auto-dispatch; verify each in-app status is `resolved` (F22).
Recovery: the reopen path; if evidence is wrong, resolved -> in_progress plus a correction comment and an issue reopen.
