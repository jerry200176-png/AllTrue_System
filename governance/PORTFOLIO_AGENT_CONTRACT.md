# Portfolio agent governance overlay

This file is committed because a cloud or mobile agent may not have access to
Ubuntu's `/home/jerry`. The canonical, detailed policy is maintained in
`jerry200176-png/portfolio-ops` under `governance/`; this file is the portable
minimum that travels with each governed repository.

## Required behavior

- Treat Cursor, Codex, Claude Code, and Cubelv as untrusted writers.
- Before writing, identify the repository, branch/worktree, task scope, risk,
  and verification plan. Never work directly on the default branch.
- Read this repository's committed instructions and ExoProtocol's `.exo/`
  constitution/lock when present. Do not edit governance files to make a task
  pass or to bypass a lock, ticket, session, CI check, or review.
- Every change goes through a pull request. Required checks, risk-appropriate
  review, rollback evidence, and product-specific controls are the acceptance
  criteria; an across-the-board human approval requirement is obsolete.
- T0 and T1 work may be implemented, reviewed, merged, and closed by the
  Agent when the required gates and evidence pass. T2 work additionally needs
  independent review, required CI, and a documented rollback boundary before
  autonomous merge.
- T3/protected work may be researched, implemented, tested, reviewed, and
  prepared as an evidence package. Stop for Founder approval before production
  activation, production data mutation or repair, migration/schema cutover,
  billing or entitlement semantics, identity/authentication/authorization,
  destructive or irreversible actions, backup restore, security-sensitive
  credential changes, or major product/brand direction.
- AllTrue remains in active but bounded product development. A draft RFC or
  spinout proposal does not impose a global feature freeze.
- Agents may triage and close issues when the Evidence Contract is satisfied;
  in-app bugs still require the public reporter-facing workflow. Deployments
  and workflow dispatch use only the product's committed control plane.
- Prefer mature open-source tools for generic lint, security, workflow, and
  policy checks; keep company-specific risk, provenance, evidence, and release
  boundaries in committed policy and CI.

If this overlay conflicts with a stricter product or safety rule, the stricter
rule wins. If the overlay or required governance context is unavailable, stop
and report the missing context instead of inventing a replacement.
