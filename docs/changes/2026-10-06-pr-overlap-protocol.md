## 2026-10-06 — chore(ci): advisory PR overlap check and "Parallel agents" protocol
<!-- release-notes: silent_ship=silent-2026-10-06-pr-overlap-protocol -->
<!-- silent-reason: 只新增多個 AI 工作階段並行時避免改到同一批檔案的內部提醒與規則，教職員看到的畫面完全不變。 -->
- 新增 `scripts/pr-overlap.mjs` 與 `.github/workflows/pr-overlap.yml`：每個 PR 以單一黏性留言列出同檔的其他開放 PR（僅提醒、非必要檢查）；AGENTS.md／CLAUDE.md／合併政策新增「Parallel agents」守則。
