## 2026-10-22 — 雲端交付入口與現行政策一致
<!-- release-notes: silent_ship=silent-2026-10-22-cloud-delivery-entrypoints -->
<!-- silent-reason: 僅修正 Agent／README 文件中的舊本機啟動說明，無產品或授權變更。 -->

- README、CLAUDE R6、in-app Phase B 與共用 skill 改連既有 WORKTREE_POLICY，避免雲端任務誤走已淘汰的本機 worktree／manifest 流程。
- 本機 WSL 的角色、模型工具與狀態證據標為本機適用；雲端沿用 GitHub issue／PR、review、CI 與既有 checkpoint，不新增第二份 backlog。
- 不改 SSH 唯讀例外、Founder 決策、hold、部署或正式資料權限。Refs #4086、#4088。
