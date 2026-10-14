# Founder 常設決定（Standing Decisions）

> 本 repo 對 Founder 常設決定的**唯一**定義。`AGENTS.md`、`CLAUDE.md` 只放指向本檔的連結，不得另寫摘要或複本。
> 艦隊層權威表：portfolio-ops `governance/AUTONOMY_POLICY.md`（portfolio-ops#134）。兩者不一致時開 PR 修正本檔；**較嚴格的規則優先**：艦隊層只能放寬本檔未另加限制之處，不得削弱本 repo 較嚴格的本地規則或安全規則（例如聚焦回歸測試、權威紀錄核對、產品 P0／紅線）。

## 2026-10-14（二）（Cloud-only 作業；對話中逐條確認；本 repo PR #4069）

1. **T0–T2**：Agent 依既有政策（`RISK_BASED_MERGE_POLICY.md`、本檔 2026-10-12 各條）自主開發、審查、合併與交付，不逐項請示。範圍不明或超出本檔授權時仍須提出。
2. **預先批准的正式寫入（allowlist，只授權不放寬）**：只限下列 in-app 回報狀態與公開回覆，且各項須其執行路徑已依第 3 條合併後才生效：
   - (a) Phase A：in-app 回報 `new`→`triaged`＋符合 `CHAT_BUG_SYSTEM.md` §3.8 的公開回覆（必含 GitHub issue URL）。
   - (b) Phase C：**確實已修復且部署驗證通過**（版本＋health＋受影響路徑 smoke，見 `docs/sop/BUG_INTAKE_TO_PRODUCTION.md` Deployment evidence gate）的回報 → `resolved`＋公開回覆。
   - (c) 14 天逾時結案：只限符合既有等待確認規則的回報，**等待天數固定為 14 天**（`--auto`／`AUTO_DAYS = 14`），不得使用手動路徑的 7 天預設或任何少於 14 天的值；**仍有問題的案件不得關閉**。
   其他一律不在此列：資料修復、migration、credential、新增資料存取、權限／安全邊界變更，仍逐案問 Founder。
3. **#3943／#3955 執行路徑**：必須改走既定的正式授權執行器（ADR-POP-008：Pi 本機 `pop:execute-approved`），**不得保留 GitHub runner → SSH 的寫入例外**。依 `docs/pop/adr/ADR-POP-015-standing-policy-inapp-lifecycle.md`，#3943／#3955 本身關閉不合併，可重用部分移植到 ADR-POP-015 的後繼實作 PR；本條的自行合併授權適用於這些後繼實作 PR（`RISK_BASED_MERGE_POLICY.md` §Founder standing exception 的第二條預先批准路徑）。後繼實作 PR 須為每條受影響的權限／寫入路徑（含跨校區與空 scope 必須 fail closed）新增或更新聚焦回歸測試並在 exact head CI 執行，且完成獨立審查、最新 head CI 全綠、無未處理 P0／P1、**本 PR 引入的回歸不論嚴重度一律修正**、所有 review thread 皆回覆並 resolve（即 2026-10-12 第 4 條完整 review 規則）後，Agent 可自行合併，不必再取得同一個 GO。
4. **部署**：範圍內每個 PR 皆 T0–T2 者依既有規則自動上線；**含 T3 的部署每次由 Founder 批准**。
5. **治理變更**：會**授予、擴大或改變權限、核准方式或受保護邊界**的治理變更（含 `docs/governance/`、`AGENTS.md`、`CLAUDE.md` 及控制面規則），Agent 先完成並提供具體 diff，**由 Founder 批准後才合併**。純文字修正、證據紀錄、清單／inventory 更新等不改變權限或邊界的治理文件變更，依一般風險分級處理（`COMPANY_CONSTITUTION.md` §3：不另加 Founder 全面核准）。
6. **唯讀診斷**：先提交設計審查；本清單不授予任何新的資料存取權限。
7. **期限與紀錄**：本節授權無固定期限，Founder 可隨時撤銷。每次使用第 2 條授權須在對應 issue 留下案件 ID、執行紀錄（POP request／execution ID；Phase C 另附 deploy workflow run 連結）與驗證結果；公開紀錄（GitHub issue／PR／commit／log）不得含個資。
8. **多 Agent 分工**：Claude 與 Codex 在共用協調紀錄（GitHub issue）登記各自負責的 PR／branch 與實作／審查角色，不共編同一分支。

## 2026-10-14（對話中「好，照你建議做 go」；批准「批次核准：一天集中 1–2 班」）

1. **發車班表（release train）改為一天兩班**：台北 12:17、18:17（UTC 04:17、10:17），取代 2026-10-07 的每小時一班（3A）。每班最多要 Founder 核准一次，收齊上一班之後合併的所有 Founder 級改動。全部 R0–R2（或已有 Founder GO）的範圍照舊隨自己的 CI 自動上線，不等車。
2. **核准關卡不變**：`deploy.yml` 只自動 dispatch `phase=release-train`（GitHub cron 會被吞掉或延遲，所以 merge 後的 deploy run 也會補發）；車仍停在 `production-activation` 等 Founder 核准，Agent 不得代為核准或改 Environment 設定。

## 2026-10-12（對話中「照推薦」；本 repo PR #3899）

1. **實作既定規則的 R3 程式（帳務／權限）**：PR 內為每條受影響的金流／權限路徑新增或更新聚焦回歸測試並在 exact head CI 執行＋exact head required checks 綠＋獨立 review（Codex 或 reviewer agent）＋無未解 P0／P1 → Agent 自行 **merge**（僅限 merge）。**獨立 review 由 Agent 自行確認後才加 `queue`**：land queue 目前只檢查 required checks 與未解 thread 數，尚未驗證 review 證據（尚未自動化，見 #3972）。**部署不在此例外內**：含 R3 的範圍仍依 `deploy.yml` 既有 release train／production-activation Founder 核准上線（控制面尚未支援此例外，見 #3961）；上線後驗 `deployment.json`／health，**並依 `EVIDENCE_CONTRACT.md`（R2/R3）直接觀察受影響的 production API／UI 路徑並核對權威紀錄（ledger／session／權限判定）**。**新的帳務語意、新的 entitlement 規則或身分／權限邊界變更不在此範圍，仍問 Founder。** 對應條文：`RISK_BASED_MERGE_POLICY.md` §Founder standing exception。
2. **仍只限 Founder**：正式資料修改、production migration 執行、credential rotation、不可逆操作。GitHub Environment reviewer 等平台關卡維持，直到 Founder 自己改。
3. **關 GitHub issue**：merge＋上線驗證（版本＋health＋受影響路徑 smoke）＋（**僅限對應 in-app 回報的 issue**）已在 in-app 公開回覆即可關 GitHub issue，不等回報者確認；復發再開。**受影響路徑 smoke 與「回報者回覆問題仍存在時重開 GitHub issue」都由 Agent 手動執行**：Phase-C 關 issue 前不會驗證 smoke，回報者拒絕驗收也不會自動重開已關的 issue（尚未自動化，見 #3972）。重複／被取代者附證據可關。**in-app 回報本身的狀態不變**：仍依 `EVIDENCE_CONTRACT.md` 標 `resolved`，要等回報者驗收或逾時政策才 `closed`。
4. **Review 停止規則**：P0、P1 一律 merge 前必修；**本 PR 引入的回歸（regression）不論嚴重度一律必修**；其餘 P2 集中修一輪後 merge；之後新出現、且非本 PR 引入回歸的 P2 **開 follow-up issue**，不擋 merge。每個 review thread 都要回覆並 resolve（延後的 P2 回覆中附 follow-up issue 連結）。
5. **既有明確 hold 維持**：#3862、#3785、RFID 真機。
