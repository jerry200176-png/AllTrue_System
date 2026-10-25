## 2026-10-25 — Preserve failed in-app probe evidence
<!-- release-notes: silent_ship=silent-2026-10-25-preserve-failed-probe-evidence -->
<!-- silent-reason: diagnostic artifact transport only; no product behavior or data change -->

Refs #4496. The detail dump encrypts its existing private envelope before checking
target identity and decision-grade validity. Failed probes still fail the job;
their diagnostic context remains available to the authorized custodian instead
of leaving only a public metadata summary. The query, scope, credentials and
evidence gates are unchanged. A regression executes the actual shell with
errexit and verifies successful, failed, mismatched and missing envelopes,
encrypted round trips and absence of private text from logs/public artifacts.
