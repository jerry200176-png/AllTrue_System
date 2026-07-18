# Actions SHA pin inventory (pilot)

**Date:** 2026-07-18  
**Policy:** Do **not** mass-pin all `actions/*@vN`. Pilot one workflow, then expand.

## Pilot

| Workflow | Action | Before | After | Trust | Rollback |
|----------|--------|--------|-------|-------|----------|
| `docs-integrity.yml` | `actions/checkout` | `@v7` | `@9c091bb…` (= v7.0.0) | GitHub official | revert to `@v7` |
| `docs-integrity.yml` | `dorny/paths-filter` | already SHA | `7b450fff…` (# v4) | third-party; already pinned | keep |

## Inventory method (for expansion)

For each `uses:` line: source, maintainer, version tag, commit SHA, Dependabot compatibility, rollback.

## Decision

Expand pin only after pilot CI is green for ≥1 week and Dependabot can propose SHA bumps (or manual cadence).
