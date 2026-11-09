## 2026-11-09 — chore(tooling): bounded code context and resilient pinned tool downloads
<!-- release-notes: silent_ship=silent-2026-11-09-agent-context-and-downloads -->
<!-- silent-reason: development-only context tools and CI download reliability; no product screen, data access or approval change -->
- Pin MIT Repomix 1.19.0 and ast-grep 0.51.1 in an isolated tools package. Use selected paths and a 12,000-token budget; keep compression opt-in after the Vue measurement increased output size. SOP/policy reads retain the existing exact section/hash reader.
- Use curl's bounded retry behavior for transient release-download failures in actionlint, shfmt and gitleaks setup. Actionlint reuses a checksum-verified archive within the local user cache; every use still verifies the pinned digest. No failed scan becomes a pass, and no trigger, permission or approval boundary changes.
