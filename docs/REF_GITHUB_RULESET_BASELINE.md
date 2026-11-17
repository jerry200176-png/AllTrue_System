# GitHub Repository Ruleset

The live ruleset is the source. This file keeps no snapshot (it went stale: strict, thread resolution and the required-check list all drifted).

The retired `Agent Session Provenance` pass-through can be removed once the live required contexts no longer include it (#4103). That condition was verified on 2026-11-17; its removal changes neither the ruleset nor any current required check.

```bash
gh api repos/jerry200176-png/AllTrue_System/rules/branches/main
gh api repos/jerry200176-png/AllTrue_System/rulesets
```

Why `required_approving_review_count` is `0` and how to switch: `docs/governance/RISK_BASED_MERGE_POLICY.md` §Review topology.
