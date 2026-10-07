## 2026-10-07 — ci(deploy): no manual production approve when every PR is R0-R2 or Founder-GO'd
<!-- release-notes: silent_ship=silent-2026-10-07-evidence-gated-production-activation -->
<!-- silent-reason: 只改部署核准流程，教職員畫面與功能不變。 -->
- 部署前由 workflow 自己檢查：production 到目標之間每個已合併 PR 都是 R0–R2，或 PR 內文有一行 `Founder GO: Jerry YYYY-MM-DD approves #N at <head SHA>`（且該 head 的改動與合併內容相同、有 Rollback、合併後內文沒改過），就經 `production-auto` 環境自動上線，不再等手動核准（Founder 1A）；任一 PR 缺 GO 或查證失敗，照舊停在 `production-activation` 等核准，摘要會列出缺 GO 的 PR。merge 後自己的 CI 跑完即可部署，發車班表變成備援（3A）。
