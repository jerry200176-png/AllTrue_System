# Founder 常設決定（Standing Decisions）

> 本 repo 對 Founder 常設決定的**唯一**定義。`AGENTS.md`、`CLAUDE.md` 只放指向本檔的連結，不得另寫摘要或複本。
> 艦隊層權威表：portfolio-ops `governance/AUTONOMY_POLICY.md`（portfolio-ops#134）。兩者不一致時以 portfolio-ops 為準，並開 PR 修正本檔。

## 2026-10-12（對話中「照推薦」；本 repo PR #3899）

1. **實作既定規則的 R3 程式（帳務／權限）**：exact head required checks 綠＋獨立 review（Codex 或 reviewer agent）＋無未解 P1 → Agent 自行 merge 並走既有 release train；上線後驗 `deployment.json`／health。**新的帳務語意問題仍問 Founder。**
2. **仍只限 Founder**：正式資料修改、production migration 執行、credential rotation、不可逆操作。GitHub Environment reviewer 等平台關卡維持，直到 Founder 自己改。
3. **關 issue**：merge＋上線驗證（版本＋health＋smoke）＋已回覆 in-app 即可關，不等回報者確認；復發再開。重複／被取代者附證據可關。
4. **Review 停止規則**：P1 必修；P2 集中修一輪後 merge；之後新出現的 P2 **開 follow-up issue**，不擋 merge。
5. **既有明確 hold 維持**：#3862、#3785、RFID 真機。
