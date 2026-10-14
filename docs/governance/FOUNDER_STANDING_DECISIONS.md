# Founder 常設決定（Standing Decisions）

> 本 repo 對 Founder 常設決定的**唯一**定義。`AGENTS.md`、`CLAUDE.md` 只放指向本檔的連結，不得另寫摘要或複本。
> 艦隊層權威表：portfolio-ops `governance/AUTONOMY_POLICY.md`（portfolio-ops#134）。兩者不一致時開 PR 修正本檔；**較嚴格的規則優先**：艦隊層只能放寬本檔未另加限制之處，不得削弱本 repo 較嚴格的本地規則或安全規則（例如聚焦回歸測試、權威紀錄核對、產品 P0／紅線）。

## 2026-10-14（三）（全面搬到 cloud；對話中 Q1–Q24 逐條確認，最後「對」；本 repo PR #4080）

方向：所有開發移到 Claude Desktop cloud session，Founder 電腦關機也要能繼續運作。**不在舊的本機機制上加 cloud 例外，而是淘汰本機機制**（#4071、portfolio-ops#135、sunrise-cafe#440 因此關閉）。本節只記決定；各項實作 PR 依 2026-10-14（二）第 5 條治理變更規則提供 diff 由 Founder 批准後合併。

1. **工作地點**：cloud session。規則放在各 repo 的 `AGENTS.md`／`CLAUDE.md`，共用規則放 portfolio-ops；本機記憶中仍有效的規則搬進 repo 文件，過時的刪除。
2. **密碼**：cloud 環境變數只放低風險 token（測試用 API、Cloudflare Browser Run；GitHub 只限 **fine-grained、綁定單一 repo、最小權限、有到期日** 的 token，不得含 admin、secrets、organization 或不受限的 workflow 權限）。正式環境密碼只放 GitHub Environment secrets，Agent 不持有。
3. **全自動（目標狀態）**：CI 綠＋符合 2026-10-12 ④ 完整 review 規則（無 P0／P1、本 PR 引入的回歸不論嚴重度皆修、所有 thread 回覆並 resolve）→ 自動合併 → 自動部署 → 自動 health check → 失敗自動 rollback。只新增（additive）的 migration 自動執行（expand/contract）——**須先修改艦隊層 `governance/PORTFOLIO_AGENT_CONTRACT.md`（portfolio-ops）移除 migration 的 Founder stop；修改合併前，所有 migration 仍照現行規則等 Founder 核准**。
4. **仍需 Founder 在 GitHub 按 Approve**：會刪除或改變既有結構的 migration（CI 自動偵測 `DROP`、欄位型別變更等並改走 environment 審核）、修改真實正式資料、新增或更換正式密碼、其他不可回復的操作。
5. **通知與卡關**：通知用 GitHub 手機 app。Agent 需要決定時開 issue、標 `needs-founder`，寫「選項＋推薦」，然後先做其他工作，不停在對話等待。
6. **淘汰**：`agent-start` 及本機 worktree／preflight 路徑檢查、Agent Session Provenance、`local-heavy-gate`、Exo、**Founder 工作站上的舊 crontab**（**不含** Pi 上驅動 Laravel scheduler 與 `pop:execute-approved` 的 `schedule:run` cron，該 cron 保留）。每小時清 PII 已由 `pii-log-purge.yml` 在 GitHub Actions 執行，保留。每小時收 bug 已由 `bug-auto-intake.yml` 在 GitHub Actions 執行，保留；不另建本機版本。
7. **順序與資料**：AllTrue 先跑順，再改 portfolio-ops、sunrise-cafe；其他專案之後再搬。「舊資料不丟」：完整備份驗證完成前不刪本機工具，文件可先改。
8. 本節第 3、4 條是目標狀態；在對應實作 PR 合併前，現行 `deploy.yml`／release train 的審核仍照舊生效。

## 2026-10-14（對話中「好，照你建議做 go」；批准「批次核准：一天集中 1–2 班」）

1. **發車班表（release train）改為一天兩班**：台北 12:17、18:17（UTC 04:17、10:17），取代 2026-10-07 的每小時一班（3A）。每班最多要 Founder 核准一次，收齊上一班之後合併的所有 Founder 級改動。全部 R0–R2（或已有 Founder GO）的範圍照舊隨自己的 CI 自動上線，不等車。
2. **核准關卡不變**：`deploy.yml` 只自動 dispatch `phase=release-train`（GitHub cron 會被吞掉或延遲，所以 merge 後的 deploy run 也會補發）；車仍停在 `production-activation` 等 Founder 核准，Agent 不得代為核准或改 Environment 設定。

## 2026-10-12（對話中「照推薦」；本 repo PR #3899）

1. **實作既定規則的 R3 程式（帳務／權限）**：PR 內為每條受影響的金流／權限路徑新增或更新聚焦回歸測試並在 exact head CI 執行＋exact head required checks 綠＋獨立 review（Codex 或 reviewer agent）＋無未解 P0／P1 → Agent 自行 **merge**（僅限 merge）。**獨立 review 由 Agent 自行確認後才加 `queue`**：land queue 目前只檢查 required checks 與未解 thread 數，尚未驗證 review 證據（尚未自動化，見 #3972）。**部署不在此例外內**：含 R3 的範圍仍依 `deploy.yml` 既有 release train／production-activation Founder 核准上線（控制面尚未支援此例外，見 #3961）；上線後驗 `deployment.json`／health，**並依 `EVIDENCE_CONTRACT.md`（R2/R3）直接觀察受影響的 production API／UI 路徑並核對權威紀錄（ledger／session／權限判定）**。**新的帳務語意、新的 entitlement 規則或身分／權限邊界變更不在此範圍，仍問 Founder。** 對應條文：`RISK_BASED_MERGE_POLICY.md` §Founder standing exception。
2. **仍只限 Founder**：正式資料修改、production migration 執行、credential rotation、不可逆操作。GitHub Environment reviewer 等平台關卡維持，直到 Founder 自己改。
3. **關 GitHub issue**：merge＋上線驗證（版本＋health＋受影響路徑 smoke）＋（**僅限對應 in-app 回報的 issue**）已在 in-app 公開回覆即可關 GitHub issue，不等回報者確認；復發再開。**受影響路徑 smoke 與「回報者回覆問題仍存在時重開 GitHub issue」都由 Agent 手動執行**：Phase-C 關 issue 前不會驗證 smoke，回報者拒絕驗收也不會自動重開已關的 issue（尚未自動化，見 #3972）。重複／被取代者附證據可關。**in-app 回報本身的狀態不變**：仍依 `EVIDENCE_CONTRACT.md` 標 `resolved`，要等回報者驗收或逾時政策才 `closed`。
4. **Review 停止規則**：P0、P1 一律 merge 前必修；**本 PR 引入的回歸（regression）不論嚴重度一律必修**；其餘 P2 集中修一輪後 merge；之後新出現、且非本 PR 引入回歸的 P2 **開 follow-up issue**，不擋 merge。每個 review thread 都要回覆並 resolve（延後的 P2 回覆中附 follow-up issue 連結）。
5. **既有明確 hold 維持**：#3862、#3785、RFID 真機。
