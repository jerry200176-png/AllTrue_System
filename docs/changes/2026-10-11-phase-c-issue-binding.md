## 2026-10-11 — fix(inapp): Phase-C closes only the canonical issue
<!-- release-notes: silent_ship=silent-2026-10-11-phase-c-issue-binding -->
<!-- silent-reason: 只改自動結案流程，產品畫面不變。 -->
- Phase-C bound the GitHub issue to close from the FIRST issue link in the reply, so in-app #389's reply (which mentioned follow-up #3847 first) auto-closed #3847 instead of #3832. Now an explicit `"issue"` key wins, and a reply linking more than one issue closes none (#3847 reopened).
