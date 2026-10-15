# Founder 常設決定（Standing Decisions）

> 本 repo 對 Founder 常設決定的**唯一**定義。`AGENTS.md`、`CLAUDE.md` 只放指向本檔的連結，不得另寫摘要或複本。
> 艦隊層權威表：portfolio-ops `governance/AUTONOMY_POLICY.md`（portfolio-ops#134）。兩者不一致時開 PR 修正本檔；**較嚴格的規則優先**：艦隊層只能放寬本檔未另加限制之處，不得削弱本 repo 較嚴格的本地規則或安全規則（例如聚焦回歸測試、權威紀錄核對、產品 P0／紅線）。

## 2026-10-15（四）（對話中逐條確認；本節只記 Founder 實際確認的內容）

本節不含 Agent 的推薦答案。尚待確認與明確未批准的項目分列於本節末兩小節，**不得視為已決定**。

### AllTrue 帳務與課程

1. **Q2=A（帳單與改價）**：已開出的帳單不會因單價變更而靜默跟著改；任何更正都必須留下紀錄。
2. **D3（作廢重開）**：不得只因帳單金額與目前 Charge 不同就建議作廢。確實需要更正時，可以作廢＋重開，但新舊帳單必須可互相追溯：至少在備註互相引用帳單編號，並記錄原因與操作人。
3. **D4（有爭議的帳單）**：先由主任依原約定、應收金額與已收金額核對；只有確定開錯才更正。不得預設以目前單價重開，也不得重收已繳的款項。
4. **Q8=A（加堂）**：要加堂時引導到「續課」。
5. **Q9=A（合約卡）**：合約卡可顯示課程編號；可看到卡片的權限不變。
6. **D2（未繳餘額）**：有效帳單的未繳餘額必須查得到；但仍待確認的 `review_required` 金額要分開顯示，不得當成已確認的欠款。兩個頁面使用同一個金額基準。

### In-app 回報

7. **D9（resolved 的條件）**：in-app 回報要逐筆真正驗收通過才可標 `resolved`。API 從 404 變成 401 只證明請求到了登入驗證，不代表登入後功能正常，不能作為驗收證據。

### Sunrise Cafe

8. **D6=B（現金找零）**：現金結帳時，一般找零可由店員處理，且要留紀錄。折扣與退款維持原有權限；不得把退款包裝成找零。
9. **D7（非現金退款）**：非現金退款需要實際成功的證據；清除「待確認」狀態不得繞過「退款處理中」的檢查。五點退款政策**不**整包接受（見下方待確認）。

### 待 Founder 確認（未決定）

- Sunrise 五點退款政策，逐點各自待確認。
- 決定 ① 是否涵蓋 #4121、#4128。
- #4118 的合併與啟用。
- 上線（release）核准。

### 明確未批准

- 套用 production SQL。
- 啟用帳務讀取（billing read）。
- 未滿足所註明的關卡就合併到 main。

## 2026-10-14（三）補充（#4097 決定 1–4；對話中「都照你推薦」）

1. **新增型 migration（#4093 選 B）**：不再用關鍵字清單判定。作者在 PR 內文宣告 `Additive-Migration: yes`；檢查只驗結構（只新增 migration 檔；只建新表、在既有表加 nullable 欄位或索引；`down()` 只撤掉 `up()` 新加的東西；有「已存在就跳過」guard 時 `down()` 必須為空）；並須 Codex 在**最終 head** 給 👍「無問題」且無未解 thread。Codex 額度用完時不以其他審查代替，退回 Founder 批准。**身分／權限表與帳務表一律排除**，照舊由 Founder 批准。
2. **帳務／登入程式自動上線（決定 4 選 A）**：不做。照 2026-10-14 release train 每天兩班由 Founder 核准；#4096 關閉，#3961 保持開啟。之後若要做，須以 Founder 核發的範圍證據＋受影響路徑 production 檢查為基礎重新設計。
3. **`ALLTRUE_TRIAGE_TOKEN`（#4087 選 A）**：為 2026-10-14（三）第 2 條的具名例外，可放在 cloud 環境，條件全部成立：唯讀、只限 `/api/v1/triage/` 路徑、由 proxy 注入（Agent 看不到金鑰值）、預設 90 天到期。回報內容（姓名、電話、描述、留言、截圖）照舊不得貼到 GitHub。
4. **`.exo/` 刪除（決定 3）**：先在含有全部 `.exo/` 檔的最後一個 main commit 打 tag `archive/exo-20261014` 並確認內容，視為 2026-10-14（三）第 7 條的備份驗證；之後以小型刪除 PR 移除。main ruleset 不改，`Agent Session Provenance` 保留同名直接通過的 job（#4083、#4103）。

## 2026-10-14（三）（全面搬到 cloud；對話中 Q1–Q24 逐條確認，最後「對」；本 repo PR #4078）

方向：所有開發移到 Claude Desktop cloud session，Founder 電腦關機也要能繼續運作。**不在舊的本機機制上加 cloud 例外，而是淘汰本機機制**（#4071、portfolio-ops#135、sunrise-cafe#440 因此關閉）。本節只記決定；各項實作 PR 依 2026-10-14（二）第 5 條（#4069）治理變更規則提供 diff 由 Founder 批准後合併。

1. **工作地點**：cloud session。規則放在各 repo 的 `AGENTS.md`／`CLAUDE.md`，共用規則放 portfolio-ops；本機記憶中仍有效的規則搬進 repo 文件，過時的刪除。
2. **密碼**：cloud 環境變數只放低風險 token（GitHub、測試用 API、Cloudflare Browser Run）。GitHub token 一律 fine-grained、只列出需要的 repo、設到期日、最小權限；不得給 admin、secrets、organization 權限。正式環境密碼只放 GitHub Environment secrets，Agent 不持有。
3. **全自動（目標狀態）**：CI 綠＋完整 review gate（2026-10-12 ④：無 P0／P1、本 PR 引入的回歸不論嚴重度一律修正、P2 修正輪、每個 thread 回覆並 resolve）→ 自動合併 → 自動部署 → 自動 health check → 失敗自動 rollback。只新增（additive）的 migration 自動執行（expand/contract），但須等實作 PR 合併、且 `governance/PORTFOLIO_AGENT_CONTRACT.md`（本 repo 與 portfolio-ops）移除 migration 停止點後才生效；在那之前所有 migration 照舊由 Founder 批准。
4. **仍需 Founder 在 GitHub 按 Approve**：會刪除或改變既有結構的 migration（CI 自動偵測 `DROP`、欄位型別變更等並改走 environment 審核）、修改真實正式資料、新增或更換正式密碼、其他不可回復的操作。
5. **通知與卡關**：通知用 GitHub 手機 app。Agent 需要決定時開 issue、標 `needs-founder`，寫「選項＋推薦」，然後先做其他工作，不停在對話等待。
6. **淘汰**：`agent-start` 及本機 worktree／preflight 路徑檢查、Agent Session Provenance、`local-heavy-gate`、Exo、**Founder 工作站**的舊 crontab。Pi 上驅動 Laravel scheduler 與 `pop:execute-approved` 的 `schedule:run` cron **保留**。每小時清 PII（`pii-log-purge.yml`）與既有的 `bug-auto-intake.yml` 都在 GitHub Actions，保留；不另加 Claude routine 收 bug。
7. **順序與資料**：AllTrue 先跑順，再改 portfolio-ops、sunrise-cafe；其他專案之後再搬。「舊資料不丟」：完整備份驗證完成前不刪本機工具，文件可先改。
8. 本節第 3、4 條是目標狀態；在對應實作 PR 合併前，現行 `deploy.yml`／release train 的審核仍照舊生效。
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
