# POP control-plane authentication

Human director/super_admin users retain the existing Bearer-authenticated
routes. The Pi machine uses an ApiClient with Purpose=pop_control_plane and
only pop:draft/pop:dry-run scopes through dedicated routes. It is never mapped
to a human user or approval role; actor and campus are audited.

Attendance-device clients remain unable to authenticate POP. Revoke the
ApiClient to disable it. There is no HTTP execute route; approval and the
existing Pi-local scheduler remain the execution boundary. The
`course-contract-repair` catalog operation is the only Founder-scoped
exception: it requires one authenticated `super_admin` approval carrying a
`founder-go-...` reference, and remains restricted by its exact
`single_student_contract`, reversible, snapshot, rollback, and verification
catalog invariants. The proposed reviewed monthly exception below has its own exact-case boundary. All other operations retain their catalog-defined quorum;
the machine identity still cannot approve or execute.

One-time bootstrap, after deployment, remains host-local. The approved
execution path is the existing `Deploy to Pi` workflow's protected
`pop-bootstrap` phase:

    gh workflow run deploy.yml --ref main \
      -f phase=pop-bootstrap \
      -f target_sha=<exact-current-main-sha> \
      -f campus_id=<approved-id> \
      -f confirm=BOOTSTRAP_POP_MACHINE:<exact-current-main-sha>:CAMPUS:<approved-id>

The command refuses overwrite, stores only a SHA-256 hash in ApiClient, and
keeps the generated credential in restricted storage/app/private/pop-machine.key;
it is never printed, committed, or copied to the GitHub runner. The workflow
also reads it only on the Pi and sends one invalid-parameter request over the
production HTTPS endpoint; HTTP 422 proves machine authentication reached the
submit route before validation without creating a draft. The key remains
host-local, and the Pi-local scheduler remains the execution boundary.

## Founder Huang repair auth adapter

`.github/workflows/pop-founder-scoped-repair.yml` is a one-case, protected
adapter for the approved Huang Yikui math contract repair. It hardcodes the
student/class/session/invoice scope and the deployed backend SHA, requires the
typed Founder confirmation plus the `production-activation` environment, and
uses the existing host-local, draft/dry-run-only POP machine key for the first
two phases, then selects one short-lived `User.type=S` session on the Pi for
approval only. Both secrets stay in the Pi shell and are unset; the runner
receives only IDs, phase results, and PII-free audit and snapshot evidence. The
workflow never calls an HTTP execute endpoint; the
existing Pi-local scheduler performs execute and verify. It does not change the
identity, permission, or approval model and does not create a long-lived token.

OIDC is not introduced here because the application has no OIDC verifier. A
future OIDC identity-model change must be separately designed and Founder
approved; this adapter deliberately reuses the existing protected SSH path and
short-lived human session without expanding production authority.

## Reviewed monthly case-owner exception

`reviewed-monthly-accounting-correction` reuses the monthly correction strategy,
existing authenticated human routes and Pi-local executor. Its dedicated
`founder-exact-monthly-manifest` policy permits at most one exact, expiring
eligibility tuple. Eligibility does not approve financial execution.
The original `monthly-accounting-correction` remains planned and dual-role.

Eligibility is read from the catalog's JSON policy. It may contain exactly one
case: canonical parameter digest (including the signed source/target snapshot),
unique idempotency-key digest, identical requester/approver human actor digests,
exact Founder reference and UTC expiry. No student identifiers, bearer tokens,
or operational approval state go in Git. Empty, missing, malformed, multiple,
expired or nonmatching eligibility denies creation and every later phase.

Only that authenticated `super_admin` can create and approve the bound request,
including their own draft; no machine identity or borrowed human session is
accepted. Database approval after a successful dry-run remains mandatory. TTL
cannot extend beyond eligibility. Execution rechecks the policy, authentic DB
approval identity, parameter integrity, deployed SHA, snapshot and existing
transaction/verification boundaries. Source changes require a fresh review;
old catalog-version drafts cannot be reused. Git merge never approves a request.

`reversible=false` is intentional: rollback restores contract ownership and
preserves the independently verified cash correction. Automatic financial
rollback is disabled. Founder GO must explicitly cover policy activation
and the immutable case result; the actual authenticated DB approval is still required. Remove the
eligibility tuple after the completed audit to retire this one-case allowance.
