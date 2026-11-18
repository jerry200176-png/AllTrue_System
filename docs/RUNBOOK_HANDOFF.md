# 交接狀態 Handoff（活的；每個 milestone 由 coordinator 更新）

Updated: 2026-11-18 05:10 UTC（正式站 backend edf3e9e17；release train 44156043246 等 Founder Approve）
Source SHA: main（以 GitHub 為準）
Owner: coordinator（每個 milestone、PR merge、deploy 後更新；完成的項目直接刪除）

**規則**：每次接續都先核對 GitHub 與正式 manifest；未滿 7 天也可能過期。超過 7 天一律 STALE。新 SHA 勝舊 SHA；
與記憶（MemPalace、個人 auto-memory）衝突時，git / GitHub 為準。只放指標，不複製內文。

## 進行中（live state）

**Takeover order:**
1. Read this table.
2. Run `gh pr list --state open --label queue` and `gh run list --workflow deploy.yml --limit 3`.
3. Read the issue or PR in the "去哪看" column.

The trackers hold the full detail: flow is #4953 and UI is #4989. This file only points to them.

| 項目 | 狀態 | 下一步（誰） | 去哪看 |
|---|---|---|---|
| Release | 正式站 backend `edf3e9e17`（#5461）。Train run 44156043246 目標 `2162bf3ea`（#5462，R3），等 Founder Approve。#5464、#5466（R3）已合併，搭下一班 train（台北 12:17），也要按。 | Founder 按 Approve | run 44156043246、`deployment.json` |
| in-app 佇列（11-17） | 收件：每小時的 intake routine `trig_011i7BkXFqHr4TAUCz6Jos9r`（11-17 補上 repo source；之後卡住會推播 Founder）。**上線後結案：closeout routine `trig_015gK3HdmnGPFGEiQjHwJ7E5`**（台北 09:00／14:00／21:00；只結「修正已在正式站＋有上線後 smoke」的件，POP 每日 20 件上限）。11-17 已上線待結：565（#5440）、566／567（#5444）、562（#5391）。自動結案改成每次先結最舊 20 件（#5447）。不能結：550／551（舊合約資料未修）、563（還有 2 項）、569（根因見 #5446，要總部補開帳單）。553 已上線、issue 已關。514（原課程已由主任作廢重開到 5 月）、555（#5046 body 補 `Refs #5374`）交給 closeout routine。**還有 9 張舊帳單月份放錯**（11/7 前開立、合約日期事後改過，舊修復不收）：#5456 讓修復改看帳單自己的日期 → 上線後 Claude 跑 `pop-billing-period-rekey.yml` dry-run → 清單應正好 9 張 → Founder approve。568（#5439）資料沒有重複，疑似畫面沒重新載入，要在 staging 重現。530（#5151）要在 staging 重現。修 in-app 前先在 intake issue 留 `Claimed by`（DELIVERY_LIFECYCLE 階段 5）。 | routine 自動；個案看各 issue | 分診 API、各 issue |
| 帳務中心重整 | 計畫 11-17 核准（月對帳表）；PR1–PR4 已上線（#5414、#5428、#5430、#5435、#5441、#5448）。**11-18 當月學收併進月對帳表**（Founder：一頁、一套規則、不用 PIN）：學收由帳單引擎算 #5462（等上線）、畫面 #5463（已上線）、舊頁導向月對帳表 #5464、刪舊 API #5466（都已合併）。之後：合約卡只留堂數與時間、拆檔。新店舊 Excel 對帳暫停。 | Claude；上線後用唯讀分診 API 比對新舊學收合計（只回報筆數與差額） | #5407 |
| UI 改版 (#4989) | 依 tracker 順序進行。 | 見 tracker | #4989 最新留言 |
| 學習／讀書計畫 | — | 見 tracker | #4970、#5269 |
| 其餘 in-app 個案 | 514／524／527 (#5124)、521 (#5162)、522 (#5113)、540 (#5231)。 | 各 issue 最新留言 | 各 issue |
| Dependabot | #5417（composer）11-17 合併（先由 #5434 清掉 PHPStan baseline）；#5418（npm）仍紅；minimatch 安全警告 #80（中）兩個 PR 都沒修到。 | 規則見 MERGE_SOP §Dependabot PRs | #5417、#5418 |
| DB 時鐘 (#5276) | 摘要與排課生成仍用資料庫時鐘。 | 需正式 DB 時區查詢結果 | #5276 |
| 資料保存期限 (#889) | 全庫只有 RFID 待處理刷卡有 30 天清除；其他都沒有期限。 | 等 Founder 定保存表 | #889 |
| GitHub 整理 | 11-17 刪 87 條「PR 已關閉」的分支（可在 PR 頁還原）；剩 45 條沒有 PR 的分支未動（可能是進行中工作）。復發家族標籤只用 `.github/labels.yml` 的 slug（`rootfam:money` 等）；字母碼 `rootfam:M`／`rootfam:R` 11-17 已刪、議題已改標。 | — | `.github/labels.yml` |
| 資料修復總表 | 合約 812／課堂 7211 的帳本缺口仍在。 | — | #5162 |

**等 Founder：** release train run 44156043246 的 Approve；帳單月份 re-key 的 approve（dry-run 44172995817，10 張，digest `d245c11a…`，見 #5124）；其餘看 `needs-founder` 標籤（`gh issue list --label needs-founder`）。

**Founder 已定：** 2026-11-13：全站單一設計模板（#4989）；536 用 A（#5198）；共用堂數池先到先用（#5151）；學生 30 不修、主任 7 天內可自行復原非金額操作（#5162）；自動進度回覆、彙總使用計數、依校區切換課堂身分模型（#4991）；沒人用的功能移除不改版。2026-11-17：帳務計畫 4 項照推薦；in-app 546 教材匯入先不做（#5329）。

**Founder-only：**
- production Approve（release train 的 run 頁面）
- POP 的 dry-run 確認與 approve
- production 資料
- migration
- 憑證
- DNS：stable staging CNAME 改天再做

**Staging：**
- 主機 `alltrue.daan.lifenet.com.tw`（rootless Docker），只放合成資料。
- quick tunnel 網址存在主機 `~/alltrue-stage/state/public-url`。
- 密碼和 cookie 只能 pipe，絕不印出來。

**11/18 學到的：**
- 同一個問題（這個月上幾堂、該收多少、收了沒）有兩份各自寫的規則（當月學收、月對帳表），數字就會對不起來，修 bug 也會修錯那一份。新報表先找現有的規則來源（帳單引擎 `MonthlyBillingService`），舊頁要嘛同一支 API、要嘛拿掉，不要並存。
- 刪 API 的 PR 要等前端改完的 PR 合併、把 main 併進來後再排隊；Codex 會在 head 還有呼叫者時給 P1。
- `director-path-acceptance` 這支 e2e 打的是正式站，main 自己也會紅；跟 PR 無關時重跑失敗的 job 即可。
- re-key dry-run 的清單比掃描多一張時，先解開加密清單只比 ID（0700 暫存、看完刪），再交 Founder 核准。

**11/17 學到的：**
- 盤點技術債時，「已完成」要用呼叫者證明（grep 誰呼叫、值從哪來），不能只看註解或檔頭說明；#5454 第一版就因此把 TD-017 誤標完成、把 TD-065 的推播種類列少（Codex 抓到）。
- in-app 週報 issue 以前都靠手動關；#5453 起每週新報出來時自動關掉舊的。
- 合併前宣告的等級低於部署時的判定（#5417 R2、#5441 R1），部署就停下等 Founder，後面每個合併都跟著卡住。#5450 讓合併前的等級至少等於部署的判定；登入／權限這類仍要 Founder 按的，`pr_declaration.py` 會印 `Activation: needs the Founder's click`（#5449），排在 release train 前合併並告訴 Founder。
- 雲端 routine 沒接 repo source 就不能寫 GitHub，會整天回 BLOCKED 沒人知道；intake routine 現在卡住會推播。

**11/7 學到的：**
- 子 agent 會改錯 PR 的 body：#5058 被 517 的內文覆蓋過，已從編輯歷史還原。改 body 前先核對 PR 編號。
- `Release-Impact: none` 會擋 CHECK 4B，只能填 `user-visible`、`internal` 或 `no-user-facing-note`。
- review-gate 會被「Codex 在 head 上有未解 finding」擋住，就算有 R3 marker 也一樣：看 PR 上 Codex sticky comment 的 `has_unresolved_findings`，修掉或回覆並 resolve thread 後重跑 `@codex review`。
- autoland 的 PR 卡住時先看 `review/final-head` check 的標題，不要只看 land-queue。
- 本機磁碟：過期副本直接刪，真要留的放 Google Drive；C: 的 WSL vhdx 要 Founder 跑桌面的 `shrink-wsl.ps1` 才會縮。
- Claude 歷史額度經驗不代表 Codex 模型／額度；以目前 client 可用能力為準。交接只給結論、證據與未解項，不貼原始日誌。

## Deploy / POP 常見坑
- **Release train 停手**：已有 run 等 approval 時，先讀該 run 的目標 SHA 與現行 SOP；不要重複 dispatch、盲目 force-cancel 或代按 Founder approval。`.github/workflows/deploy.yml`
- **POP in-app 每日上限**：每個 operation 每 Asia/Taipei 日 20 份 report（以 report 計，不是 request）；超過時 dry-run 會過但不會執行，00:00 Taipei 後用新的 request_id 後綴重送。`docs/pop/adr/ADR-POP-015-standing-policy-inapp-lifecycle.md`
- **issueOwnsReport / SourceRef**：`github_issue_url` 必須是 open 且非 duplicate 的 issue，且 owner 或 github-actions[bot] 的內文／留言含 `SourceRef … alltrue:bug_report:<id>`；auto-intake 鏡像被關成 duplicate 會失敗 → 改用 canonical issue。`backend/app/Operations/Inapp/InappReportResolveEvidence.php`
- **fix 要連回 issue**：fix PR 標題或內文需有 `#<issue>` 或 `in-app #<bug>`（可編輯已 merge 的 PR body 補上）。
- **smoke-after-deploy**：smoke run 必須在 deploy job 的 `completed_at` 之後才算數。
- 完整流程：`docs/changes/2026-10-22-pop-inapp-resolve-after-deploy.md`、`docs/sop/DELIVERY_LIFECYCLE.md`、`docs/CHAT_BUG_SYSTEM.md`。

## 找東西
問題 → 文件的對照在 `docs/INDEX.md` 的「Takeover recall」。
