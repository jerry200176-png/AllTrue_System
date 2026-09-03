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
catalog invariants. All other operations retain their catalog-defined quorum;
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
