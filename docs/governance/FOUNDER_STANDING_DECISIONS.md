# Founder 常設決定（Standing Decisions）

> 本 repo 對 Founder 常設決定的**唯一**定義。`AGENTS.md`、`CLAUDE.md` 只放指向本檔的連結，不得另寫摘要或複本。
> 艦隊層權威表：portfolio-ops `governance/AUTONOMY_POLICY.md`（portfolio-ops#134）。兩者不一致時開 PR 修正本檔；**較嚴格的規則優先**：艦隊層只能放寬本檔未另加限制之處，不得削弱本 repo 較嚴格的本地規則或安全規則（例如聚焦回歸測試、權威紀錄核對、產品 P0／紅線）。

## 2026-10-12（對話中「照推薦」；本 repo PR #3899）

1. **實作既定規則的 R3 程式（帳務／權限）**：PR 內為每條受影響的金流／權限路徑新增或更新聚焦回歸測試並在 exact head CI 執行＋exact head required checks 綠＋獨立 review（Codex 或 reviewer agent）＋無未解 P0／P1 → Agent 自行 **merge**（僅限 merge）。**獨立 review 由 Agent 自行確認後才加 `queue`**：land queue 目前只檢查 required checks 與未解 thread 數，尚未驗證 review 證據（尚未自動化，見 #3972）。**部署不在此例外內**：含 R3 的範圍仍依 `deploy.yml` 既有 release train／production-activation Founder 核准上線（控制面尚未支援此例外，見 #3961）；上線後驗 `deployment.json`／health，**並依 `EVIDENCE_CONTRACT.md`（R2/R3）直接觀察受影響的 production API／UI 路徑並核對權威紀錄（ledger／session／權限判定）**。**新的帳務語意、新的 entitlement 規則或身分／權限邊界變更不在此範圍，仍問 Founder。** 對應條文：`RISK_BASED_MERGE_POLICY.md` §Founder standing exception。
2. **仍只限 Founder**：正式資料修改、production migration 執行、credential rotation、不可逆操作。GitHub Environment reviewer 等平台關卡維持，直到 Founder 自己改。
3. **關 GitHub issue**：merge＋上線驗證（版本＋health＋受影響路徑 smoke）＋（**僅限對應 in-app 回報的 issue**）已在 in-app 公開回覆即可關 GitHub issue，不等回報者確認；復發再開。**受影響路徑 smoke 與「回報者回覆問題仍存在時重開 GitHub issue」都由 Agent 手動執行**：Phase-C 關 issue 前不會驗證 smoke，回報者拒絕驗收也不會自動重開已關的 issue（尚未自動化，見 #3972）。重複／被取代者附證據可關。**in-app 回報本身的狀態不變**：仍依 `EVIDENCE_CONTRACT.md` 標 `resolved`，要等回報者驗收或逾時政策才 `closed`。
4. **Review 停止規則**：P0、P1 一律 merge 前必修；**本 PR 引入的回歸（regression）不論嚴重度一律必修**；其餘 P2 集中修一輪後 merge；之後新出現、且非本 PR 引入回歸的 P2 **開 follow-up issue**，不擋 merge。每個 review thread 都要回覆並 resolve（延後的 P2 回覆中附 follow-up issue 連結）。
5. **既有明確 hold 維持**：#3862、#3785、RFID 真機。
