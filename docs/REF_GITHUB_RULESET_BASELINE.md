# GitHub Repository Ruleset

The live ruleset is the source. This file keeps no snapshot (it went stale: strict, thread resolution and the required-check list all drifted).

The live required contexts no longer include the retired `Agent Session Provenance` pass-through (verified 2026-11-17; #4103). Retire it in two steps: first remove automatic triggers and queue callers while retaining manual dispatch compatibility for the installed queue, then delete the workflow after the caller update lands on main. Neither step changes the ruleset or a current required check.

```bash
gh api repos/jerry200176-png/AllTrue_System/rules/branches/main
gh api repos/jerry200176-png/AllTrue_System/rulesets
```

Why `required_approving_review_count` is `0` and how to switch: `docs/governance/RISK_BASED_MERGE_POLICY.md` §Review topology.
