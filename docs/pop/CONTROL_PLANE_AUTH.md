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
