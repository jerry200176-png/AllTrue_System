## 2026-10-06 — docs(billing): 主任學生帳務對帳 IA 重規劃契約
<!-- release-notes: silent_ship=silent-2026-10-06-director-billing-recon-ia -->
<!-- silent-reason: 只新增主任帳務對帳的規劃與接手文件，教職員看到的畫面與功能完全不變。 -->
- 新增 [`docs/plans/2026-10-06-director-student-billing-reconciliation-ia.md`](../plans/2026-10-06-director-student-billing-reconciliation-ia.md)：帳務中心為唯一學費核帳入口、學生帳務檔必須能對上「哪筆錢＝哪幾天課」、次要頁只深連、月結／堂數制用說明條而非第二套系統；INDEX 已掛導航。本 PR 僅文件，不改程式與催繳規則。
- Worker 接手包（M1 only）：[`IMPL_HANDOFF_M1`](../plans/2026-10-06-director-billing-recon-IMPL_HANDOFF_M1.md)＋[`KICKOFF_M1`](../plans/2026-10-06-director-billing-recon-KICKOFF_M1.md)（給 Claude Code／Codex；載 `alltrue-testing`，勿整包裝 agent-skills）。
