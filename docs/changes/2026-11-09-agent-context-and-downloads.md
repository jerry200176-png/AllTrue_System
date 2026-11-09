## 2026-11-09 — chore(tooling): bounded code context and resilient pinned tool downloads
<!-- release-notes: silent_ship=silent-2026-11-09-agent-context-and-downloads -->
<!-- silent-reason: development-only context tools and CI download reliability; no product screen, data access or approval change -->
- Pin MIT Repomix 1.19.0 and ast-grep 0.51.1 in an isolated tools package. Use selected paths and a 12,000-token budget; keep compression opt-in after the Vue measurement increased output size. SOP/policy reads retain the existing exact section/hash reader.
- Use official GitHub release-asset API downloads with bounded retries and the original release URL as fallback after repeated browser-route 504 failures. All three tools validate pinned SHA256 before installation/extraction. Actionlint reuses a verified archive; shfmt reuses a verified installed binary. No failed scan becomes a pass, and no trigger, permission or approval boundary changes.
