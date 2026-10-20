# POP mount denial simulation

The local atomic-mount simulation now matches the actual `runuser -u USER --
test -r PATH` argument positions after removing `sudo -n`. Previously its read
denial stub never fired, and the test stopped before its recovery checks.
The simulation also covers denial of parent-directory traversal. Both denials
must preserve the prior live file's contents, owner, and mode.

Validation: `bash scripts/pop-mount-atomic-stage.test.sh` and
`bash scripts/check-pop-mount-remote-guards.test.sh` pass locally.
These are simulated failure paths, not evidence of production sudo permission,
a successful mount, or a financial repair. Runtime permission diagnosis remains
necessary before choosing any permission change.

silent_ship: test-only correction; no staff-facing behavior change.
