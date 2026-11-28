# 交接狀態 Handoff（活的；每個 milestone 由 coordinator 更新）

Updated: 2026-11-28 06:15 UTC（`origin/main` `d770d50e3`；deploy run `46113075733` 已上線，無 waiting approve train）
Source SHA: main（以 GitHub 為準）
Owner: coordinator（每個 milestone、PR merge、deploy 後更新；完成的項目直接刪除）

**規則**：每次接續都先核對 GitHub 與正式 manifest；未滿 7 天也可能過期。超過 7 天一律 STALE。新 SHA 勝舊 SHA；
與記憶（MemPalace、個人 auto-memory）衝突時，git / GitHub 為準。只放指標，不複製內文。

## 進行中（live state）

**Takeover order:**
1. Read this table.
2. Run `gh pr list --state open --label queue` and `gh run list --workflow deploy.yml --status waiting` (a release waiting for Approve hides behind newer merge runs in `--limit 3`).
3. Read the issue or PR in the "去哪看" column.

The trackers hold the full detail: flow is #4953 and UI is #4989. This file only points to them.

| 項目 | 狀態 | 下一步（誰） | 去哪看 |
|---|---|---|---|
| Release | `origin/main` `d770d50e3`（#5722）已由 `deploy.yml` run `46113075733` 成功部署；目前無 `status=waiting` 的 release train。 | 正常持續交付 | `gh run list --workflow deploy.yml --status waiting` |
| 進行中 PR | `#5723`（登帳引導共用方案）、`#5672`（Dependabot GitHub Actions）、`#5627`（skeleton flash）。 | 依 CI / review 收尾 | `gh pr list --state open` |
| 工程治理 / 成熟度（M4–M8） | `#868`–`#870`、`#872`–`#873`、`#875`–`#886`、`#890`–`#892` 皆已結案；仍開：**`#871`（Land queue）、`#887`（主機硬化）、`#888`（IAM 盤點）、`#889`（個資與保存）**。 | 聚焦剩餘 4 項與 `SOP_MATURITY.md` | `docs/SOP_MATURITY.md` |
| 資料保存期限與個資（#889） | Founder 2026-11-17 已定保存期限表；`#5421`（`privacy:prune-logs`）已上線；學生 2 年去識別化試跑 0 人符合（2027-09 前再做）。尚待收斂：`purgeStudentRecords()` 補清孤兒個資表、上傳附件由 `public` disk 轉受保護存取。 | 拆小 PR 依序收斂 | `#889`、`docs/REF_PRIVACY_DATA_INVENTORY.md` |
| 四校資料治理（2026-11-23） | 四校唯讀掃描與 12 項帳務指標、簽到補登 POP 預演（寫入與待審均為 0）已完成。剩餘：新店 2 筆作廢帳單收據 + 1 筆已付清課程旗標未同步（F2，待精確重驗與核准）、717 筆排課關聯候選預演、以及 1–10 月未涵蓋帳單等總部回填確認。 | 總部回填 + 工程準備精確 POP 證據包 | 本地私有證據目錄（不進公開 repo） |
| DB 時鐘 (#5276) | 摘要與排課生成仍用資料庫時鐘。 | 需正式 DB 時區查詢結果 | `#5276` |
| UI 改版 (#4989) & OSS 正確性 (#4991) | 依 tracker 順序持續推進。 | 見 tracker 最新留言 | `#4989`、`#4991` |

**等 Founder：** 目前無 waiting `deploy.yml` train；其餘看 `needs-founder` 標籤（`#5475`、`#4261`、`#3419`：`gh issue list --label needs-founder`）。

**Founder 已定（近況摘要，權威定義見 `docs/governance/FOUNDER_STANDING_DECISIONS.md`）：** 2026-11-17：`#889` 保存期限表選項 A；帳務計畫 4 項照推薦。2026-11-27：AI 唯讀金鑰可讀分校學校名稱與人數（`#5681`）。2026-11-28：`schoolExamReferenceData.generated.js` 原地修改且通過純資料驗證時不因缺 patch 卡住自動上線（`#5706`）。

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
- 等 Founder 按的 release 只會有一班：新的 dispatch 會取消舊的。查「有沒有在等按」用 `--status waiting`，不要只看最近 3 筆（11-18 我因此誤報「沒有在等的 train」）。
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
