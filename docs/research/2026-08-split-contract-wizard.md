# Split-contract wizard research record

Date: 2026-08-23 (Asia/Taipei)

## Decision and scope

Design a director-only, three-step workflow for the existing unpaid count-based
contract correction gap: select already-used sessions to move, preview the
derived old/new contract counts and charges, then confirm one atomic command.
The implementation must preserve attendance/evaluation history, campus
authorization, unpaid-payment guards, and the ordinary billing lock.

## Local source of truth

- `backend/app/Http/Controllers/StudentClassController.php`: existing
  `transferSessions`, `billingCorrection`, and `purchaseBatch` commands.
- `.cursor/plans/1901_unpaid_billing_correction_2026-08-22.md`: approved
  boundary for unpaid corrections, row locking, audit, and no changes to the
  ordinary PUT path.
- `backend/app/Services/SessionDeductionService.php`: canonical observed-use
  count and counter recomputation.
- `frontend/src/components/course-management/TransferSessionsModal.vue` and
  `frontend/src/pages/CourseManagement.vue`: current split operations are
  separate and the transfer UI requires a manually-created target course.

Product decision (2026-08-23): this is an unpaid settlement flow, not a
future-entitlement transfer. Only observed used sessions remain billable;
unused sessions are explicitly waived. The new command creates no future rows;
moved rows are the complete new contract entitlement.

## Reference evidence

1. Material UI Stepper documentation (official, live page captured 2026-08-23):
   horizontal steppers are appropriate when later content depends on an
   earlier step; linear steppers enforce sequence; error steps and a compact
   mobile variant are supported. Source and tests are linked from the docs.
   https://mui.com/material-ui/react-stepper/
2. MUI Material source (MIT, commit
   `7fb01101f45fb72fdbeb3d826984030583e71ea9`, source/test paths
   `packages/mui-material/src/Stepper/Stepper.js` and
   `Stepper.test.tsx`): a maintained open-source implementation with explicit
   active-step state and test coverage.
   https://github.com/mui/material-ui/tree/7fb01101f45fb72fdbeb3d826984030583e71ea9/packages/mui-material/src/Stepper
3. `kennyhei/rhf-wizard` (MIT, commit
   `95c3a172c327a468fbb74c08d5ded0342c219e7f`, pushed 2026-01-31): its README
   and `src/wizard/Wizard.tsx` document per-step validation, async step
   submission, shared header/footer, and a final summary. It is used only as
   an interaction pattern; no code or dependency is copied.
   https://github.com/kennyhei/rhf-wizard/tree/95c3a172c327a468fbb74c08d5ded0342c219e7f

## Adaptation to AllTrue

- Step 1 is linear and selection-only. The UI lists only session rows already
  marked attended/completed/late by the existing display composable. A start
  date is retained as the new contract's historical grouping date; it does not
  create future schedule rows.
- Step 2 calls a server preview. The client never calculates an authoritative
  charge or count. The preview shows both contracts side by side and explicitly
  shows total billable amount plus waived unused sessions and amount.
- Step 3 requires a reason and a second confirmation. The submit command
  repeats all validations under source-course row lock, creates the new unpaid
  contract, moves sessions plus LearningRecord/StudentSignIn rows, then applies
  the existing unpaid correction boundary to the source in one transaction.
- Preview/submit errors remain in the modal. A failed transaction returns an
  error and leaves both contracts unchanged.
- The route remains under the existing `director,super_admin`,
  `require_campus`, and `require_password_change` middleware. Controller-level
  campus checks remain authoritative. No migration or new dependency is
  needed.

## Acceptance and rollback

- 10 purchased / 8 used, select 3: source becomes 5 sessions / 2,500, new
  contract becomes 3 sessions / 1,500, total amount due is 4,000, and 2
  unused sessions / 1,000 are waived. No future sessions are created.
- LearningRecord and StudentSignIn follow each moved ClassSession.
- Future source sessions beyond the corrected count are cancelled by the same
  existing correction logic; used rows are never deleted or rewritten.
- Existing `billing-correction` and `transfer-sessions` commands remain
  available for their narrower use cases. Reverting the application commit
  restores the old separate UI/API; already completed splits require the
  existing audit-guided data repair path and are not silently reversed.

## Risks and open decisions

- This command is money-affecting (T3/R2-equivalent) and requires independent
  review of transaction order, payment guards, audit metadata, and campus
  isolation before merge.
- Paid, partially paid, pending-report, package, monthly, hourly, and
  usage-settled courses remain blocked. The result remains unpaid: the two
  corrected course charges, and any existing unpaid invoice, are the amounts to
  collect later. This command creates no Payment record and does not mark the
  course paid.
