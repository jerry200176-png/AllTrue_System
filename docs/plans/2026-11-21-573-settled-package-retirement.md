# In-app #573 settled package retirement

The single package in #573 still produces a renewal reminder after its sole member was settled. The package has zero sessions remaining. Its only invoice is fully paid by an intact confirmed receipt. The app's existing stop flag distinguishes a closed package from an active package and #5578 verifies the worklist behavior.

## Preview and approval

Use the existing reviewed-case POP control plane for the exact campus, package, course and invoice in #573. Pin a fresh digest of the fields read by the strategy. Preview compares that digest and refuses unsettled invoices, additional members, outstanding sessions, a receipt mismatch, or other drift. The ordinary reviewed financial tuple binds that exact request and its human requester and approver to the activation. The POP executor runs it locally with an authenticated Founder decision.

## Apply and recover

Only the exact package's stop flag changes. The update, before and after comparisons, audit and execution record share one transaction. Verification rechecks the same member, lessons, invoice items, invoice, receipt and payment. The original package timestamp is preserved. The native recovery path restores the stop flag only if the current digest matches the recorded after-state, then verifies the original digest.

After applying, verify the target row disappeared from the scoped accounting center and the ledger is intact. Send the verified outcome to the original report and update the matched item in #574. The other item in #574 requires its own course reference.
