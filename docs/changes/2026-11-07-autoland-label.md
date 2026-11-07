## 2026-11-07 — feat(ci): `autoland` label adds `queue` once the merge gates are green
<!-- release-notes: silent_ship=silent-2026-11-07-autoland-label -->
<!-- silent-reason: 只新增合併流程的自動加標籤工作流程，教職員看到的畫面與功能完全不變。 -->
- `autoland.yml`（PR／留言／review／check_suite 事件＋每 10 分鐘排程）：帶 `autoland` 標籤的 PR，在必要檢查、Codex 對 head 的結論、所有討論串已解決、R3 標記（需要時）都由 `land-queue.mjs`／`review-gate.mjs` 判定為綠燈後，自動加上 `queue`。只加標籤，不合併、不核准部署或環境。
