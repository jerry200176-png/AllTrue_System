# Exact deployment receipt (#3503)

## Goal and authority

Bind a successful `deploy.yml` application activation to its resolved target SHA, Actions run/attempt, and public runtime identity. This is R3/T3 because it changes the production workflow. Existing activation, migration, rollback and Founder gates remain unchanged. Work stays in this agent-start worktree; merge requires the current control-plane authorization.

## Acceptance

- Only after `Deploy` and post-deploy smoke succeed, the existing executor produces one machine-readable receipt for that run attempt. It verifies the public runtime manifest's backend SHA equals the resolver's exact target.
- Receipt records repo, target SHA, workflow revision SHA, run ID/attempt/event, a pre-deploy attempt marker, deployed/observed timestamps, runtime manifest, and verification state. It accepts the manifest writer's legacy short or missing frontend identity while requiring an exact full backend SHA. Unknown application artifact digest and configuration identity remain explicitly unknown until a separate immutable-build/config control exists.
- A manual or repository dispatch may have a workflow head SHA different from target; the receipt still names the resolved target. Waiting/skipped/failed deploys cannot emit a successful receipt. A failed receipt upload leaves the Actions job failed, never a false verified result.
- Focused tests cover SHA mismatch, malformed manifest, dispatch head/target difference, rerun attempt, and missing metadata. No secret or private configuration value enters the receipt.

## Steps and stop points

1. Read existing resolver, deploy success path, manifest contract and available artifact mechanism; avoid a second release database or executor.
2. Add a small validated receipt builder and focused tests, then invoke it after successful deploy and upload with the pinned existing artifact action. Expose artifact name/version in the job summary.
3. Run focused tests, workflow actionlint, existing deployment contract tests, preflight/provenance and diff checks. Obtain independent review and exact-head required GitHub checks in a PR.
4. Stop before workflow merge or production activation. Runtime receipt acceptance requires a later authorized real deploy; historical manual runs remain UNKNOWN because a new workflow cannot retroactively create proof.

Rollback before activation: revert the workflow/script commit. If activation later occurs, removing the receipt mechanism changes reporting evidence only; deploy rollback remains the product's existing authorized procedure and does not restore data.
