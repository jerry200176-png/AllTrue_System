# Xinzhuang fee cases: bounded read-only evidence

Repository AllTrue_System; base e60821ed; task chore/task-xinzhuang-fee-case-probe-20261018. R2/T2 read-only production-case-dump extension per AGENTS.md. Existing case references supplied by the user; no new credentials or rights.

Goal: obtain current contract/invoice/report/payment relationships for the three supplied references under campus11. Public production branch metadata confirms 新莊分校=11; campus9 probes were mis-scoped and are not case evidence. No names, screenshots, free text or decrypted financial payload goes to GitHub.

1. Add a separate bounded campus11 case, preserving the campus9 and secret-only inapp414 paths. Enforce exact authorized course whitelist, refuse unrelated inputs and verify campus identity. Use explicit field projections, bounded row counts, a server-enforced read-only repeatable-read transaction and rollback.
2. Report missing schema, truncation or ownership inconsistencies as incomplete evidence; never infer a repair from rate times quantity or from missing rows. Preserve existing encryption, status-only logs, timeout and cleanup controls.
3. Reuse existing workflow render/lint/transport/encryption tests; add synthetic database fixtures for real ownership, ledger projection, bounds, read-only transaction and cleanup behavior. No production IDs in DB fixtures.
4. Exact-head complete CI, independent review, all findings addressed, inspect removed lines and normal land queue. Rollback: remove the new case; no production state to reverse.
5. Run the merged bounded probe for the three references, decrypt with the existing local key only, prepare private manifests and honest acceptance status.

Stop points: no direct production SSH, writes, migrations, enablement, credential changes or private-report secret bypass. Any financial repair or release remains a separate protected action requiring concrete evidence and Founder approval. Case acceptance stays0/3 until actual affected-path verification.

## Verification

Synthetic MySQL suite6tests/44assertions passed in1:18.876, including real SQLSTATE25006 on an attempted write, rollback/outer-transaction ownership, net-zero reversal versus stale stored PaidAmount, private-text exclusion, campus identity, invoice/report ownership, shared-item redaction, row budgets and unlinked obligations. After adding optional correction-history projections, the affected real-ledger/unlinked test passed1test/17assertions in14.198seconds. Existing workflow render/lint/bounds/transport/encryption/cleanup suites plus new allowlist/transport cases passed39tests in33.825seconds; no SSH host contacted.

Initial DatabaseMigrations teardown hit an existing unrelated migration-down FK dependency; switched to the maintained AgreedFeeRefreshDatabase transaction/schema lifecycle, ending its outer transaction before the server READ ONLY transaction. No production migration or unrelated down method was changed. Hosted CI and independent review remain required.
