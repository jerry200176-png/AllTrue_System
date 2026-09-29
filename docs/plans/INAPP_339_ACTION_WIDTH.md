# In-App #339 — bounded receivables action width

SourceRef: `alltrue:bug_report:339`; canonical issue #3268 (related #3083).
Baseline: `448aa4cebdea83c0e42d519657c48468feb2ab7a`.
Production remains `8f69c5019b75393c6dd6ab55b8f33b72b5ad26a8`.

## Evidence and cause

Read-only detail run 36216117866: new, no comments/status transitions,
complete reporter history. Existing attachment 285 was retrieved through the
service's normal `/storage/` browser URL and privately inspected; no private
pixels or identities are committed or posted to GitHub.

The screenshot shows a wide sticky operation cell covering neighbouring
accounting data. Existing controls plus the amount-discrepancy notice form
one nowrap flex row. The actual mounted regression reproduces a 388px action
cell at 900px width. #3156 fixed action reachability, but did not bound the
sticky region's width. This is a separate presentation recurrence, not a
wrong amount or evidence that an accounting action failed.

## Scope and risk

R1/T1 CSS presentation only: wrap existing receivables actions at desktop
widths and bound their width. Preserve every label, control, DOM order,
handler, disabled condition, warning, numeric source/calculation, API,
permission, mobile card layout and accounting semantics. No schema/data
operation, new capability, action prioritisation or menu is introduced.
The auto-fix envelope's existing-semantics/reversibility and thirteen
non-protected conditions apply to this exact CSS diff, independently of the
one-time #362 adjudication. That adjudication is not reused as authority.

## Acceptance and review

- Actual browser regression RED at baseline: 388px >315px at 900px.
- GREEN at 900/1280/1440px: <=35% action cell, every original control
  contained/reachable with >=44px height, unchanged keyboard Tab order,
  each amount fully readable through normal horizontal table scrolling.
- Existing desktop/tablet/mobile, accounting tabs, loading/empty/error/retry
  and session dialog regressions must pass; required exact-head CI before merge.
- Confirm the complete template/script prefix is byte-identical to baseline.
- The pre-existing browser fixture omitted `payable_status` and the payable
  amount/outstanding fields returned by `BillingPayableResolver` and
  `AlertController`. Restore those fields for the existing synthetic invoice
  rows; retain all original selection/sorting assertions and production guards.
- The standard changelog generator updates its draft bundle as a necessary
  derived artifact; the corresponding existing staff digest records only this bounded presentation change.
- Design: retain the existing operational accounting table, design tokens,
  type scale, icon set and functional motion. No new visual system. Local
  screenshot review: AI Slop 1/10; distinctiveness 8/10.

## Release and remaining claims

Normal PR/checks/review -> merge -> canonical deploy.yml/environment gate.
This task grants no new production activation. Do not infer engineering
delivery, production user-path observation or reporter acceptance from local
rendering. Phase-C occurs only after risk-appropriate deployment/version/health
evidence through the existing authorised writer; reporter acceptance stays
independent. Original #339 wording does not establish which aesthetic concerns
the reporter intended, so the closeout claim must stay bounded to operation-area
crowding and readable data; any other concern remains unresolved.

Rollback code target is the pre-change baseline above; a PR revert plus normal
authorised deployment is the recovery proposal. It is not a proven post-success
rollback execution. No migration or business-data rollback is involved.
