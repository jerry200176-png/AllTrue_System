## 2026-10-08 — chore(github): CODEOWNERS only covers deploy/governance control
<!-- release-notes: silent_ship=silent-2026-10-08-codeowners-governance-paths -->
<!-- silent-reason: 只改 GitHub 審查設定，產品畫面不變 -->
- CODEOWNERS now lists only `.github/` and `scripts/governance/`; a new ruleset makes collaborator PRs touching them need the owner's approval (security review, #3797). Other files merge as before.
