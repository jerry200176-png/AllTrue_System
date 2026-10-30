# 帳務中心文案與版面規格（Billing Copy UX Spec）

日期 2026-10-30｜範圍：研究與規格，不改程式｜第 1–3 節＝文案（R1）；第 4–9 節＝投訴證據、流程、資訊架構、切片（Founder 追加範圍）
路徑基準：`frontend/src/`（worktree DELIVERY-20261025）。行號為當日讀到的版本，實作前用 grep 對一次。


## 實作更正（2026-10-30，PR #4681 合併後；以此段為準，覆蓋下文）

- **T5 / F3 / F-6 已被 #4681 涵蓋**：「當月學收」已有 Verdict 逐列 已收／部分／未收／待確認／已免收 與摘要「已收 X / 應收 Y，未收 N 人」，帳務中心有連結。**不做 F-6、不新增月份彙總 API。**
- **F4**：保留頁面、**不改名「當月估算」**。月學收欄標題改為「上課應收（估算）」，狀態欄顯示實際收款。
- **F1**：#4677 已把無可撤銷的異常卡改成事實行（應收／收款紀錄合計／多收）＋「回報給系統管理員」按鈕。保留，只對齊用字。
- **F2 = A（Founder 2026-10-30 決定）**：登記成功後結果頁顯示「現在確認入帳」，同一位主任可按。仍是兩個明確步驟，各自一次 API 呼叫與稽核，不得合併。在 F-2 實作（L3＋既有 `initialAction`）。
- 實作順序：R1 → R1b → F-1 → F-2 → F-3 → F-4 → F-5，一次一個 PR，不疊。F-3 建立在 #4670（已上線，共用方案已有單一主列＋繳費單／回報按鈕）之上。

## 0. 證據與限制（誠實標註）

| 層 | 實際讀到 | 沒讀到 / 限制 |
|---|---|---|
| 官方文件 | Stripe invoice lifecycle（Browser Run 讀取）；Atlassian Lozenge usage；Shopify Polaris Badge content rules（WebFetch，shopify.dev）；Jackrabbit「Post individual payment」 | Xero、QuickBooks、Square、Teachworks、My Music Staff、Stripe 文案指南：Browser Run 被 Cloudflare 429 限流，WebFetch 回空頁。**這些沒有驗證，下列模式不引用它們。** 要補需等限流解除再讀。 |
| 即時行為 | 僅 Stripe 文件中對 Dashboard badge 的描述（"Past due"、"Retrying"，hover 有 tooltip） | 無 demo 帳號可看，沒有 UI 截圖 |
| 開源（鎖版本） | Invoice Ninja **v5.13.51**；Akaunting **3.3.0**；Odoo **17.0**（branch head 220f33cd） | Invoice Ninja v5 前端是 React（獨立 repo），不是 Vue；只取後端 `lang/` 字串 |
| 本 repo | 全部指定檔案；`courseMoneyState.js`；`MonthlyDraftsPanel.vue`、`StudentBillingList.vue`、`PaymentAllocation.vue`、`ContractCard.vue`；後端 `AccountingController`、`PaymentReportController`、`MonthlyRenewalPeriodService`、`Billing/ContractMoneyState.php`；PRD v2 §0；BILLING-UX-QUALITY plan | `BillingPayableResolver` 與 `ContractMoneyVerdict` 沒有主任可見字串，只有狀態碼 |

## 1. 大公司的做法（10 條）

1. **一個狀態字加一個金額，不寫句子。** Stripe 發票只有 draft / open / paid / void / uncollectible 五個狀態，細節（Past due）放 badge 與 hover（`docs.stripe.com/invoicing/overview`, "Invoice statuses" 表，2026-10-30 讀取）。Odoo 17 的 `payment_state` 只有 Not Paid / In Payment / Paid / Partially Paid / Reversed（`odoo/odoo@17.0 addons/account/models/account_move.py:45-51`）。對非會計：掃一眼就懂，不用讀。
2. **狀態詞全站固定，同一狀態同一顏色。** Polaris："Use consistent tones for the same status across your app"（Badge content guidelines，shopify.dev，2026-10-30）。Atlassian 同樣區分 semantic 色與裝飾色，且「不可只靠顏色」，必須有文字（atlassian.design/components/lozenge/usage）。
3. **標籤 1–2 個詞、過去式。** Polaris："Keep labels to 1-2 words"、"Always use past tense for status labels"。我們的「已回報付款，待你核對入帳」太長，會被截斷。
4. **按鈕名稱說結果，不說流程。** Invoice Ninja 用 `Mark Paid`、`Add Payment`、`Send Invoice`（`invoiceninja@v5.13.51 lang/en/texts.php`、Akaunting `resources/lang/en-GB/invoices.php:mark_paid / add_payment`）。主任看到「確認入帳」比「登記已回報」少一層翻譯。
5. **規則違反時直接說事實，不丟給使用者去猜。** Invoice Ninja 超收被關閉時只顯示 "Company doesn't support overpayments."（`texts.php:3814 over_payments_disabled`）；Akaunting："The amount you entered passes the total: :amount"（`invoices.php over_payment`）。一句事實加金額，沒有「請找某某」。
6. **付款時逐筆分配，預設先填最舊。** Jackrabbit 付款頁："Apply to Oldest Fees First"，並逐筆顯示 Applied / Unapplied Amount（help.jackrabbitclass.com/help/post-individual-payment）。我們的 PaymentAllocation 已是此模式，只差文案。
7. **過期與逾期用 badge 表示，說明藏在 hover。** Stripe：open 發票可顯示 "Past due" 或 "Retrying"，hover 有 tooltip。長解釋進「為什麼？」展開區，不佔主畫面。
8. **自動轉換狀態，不要使用者記規則。** Stripe：低於最小可收金額的發票自動轉成 paid（同上頁）。我們「到今天未繳 / 之後還會到期」已是同類設計（D8），不要再加第三個數字。
9. **只列使用者做得到的下一步。** Atlassian 訊息指南要求說明「發生什麼、為什麼、怎麼辦」（atlassian.design/foundations/content，本次未成功讀取全文，列為待驗證）。內部原則：沒有按鈕可按的步驟，不能寫成指示（見 #446）。
10. **衝突資料標「需核對」，不猜。** Odoo 的 `in_payment` 與 `reversed` 是獨立狀態，不歸入已付。我們的 `LEGACY_FLAG_UNVERIFIED_LABEL` 同理。

## 2. 狀態詞表（全站唯一一套）

來源已存在：`lib/courseMoneyState.js:164-176 TUITION_STATUS_CONFIG`、`:221 INVOICE_STATUS_LABELS`、`:255 REPORT_STATUS_LABELS`。**不新增算法，只收斂字。**

| 詞 | 顏色 token（沿用現有 class） | 意思（一句） | 現有 class |
|---|---|---|---|
| 未繳 | `--ds-danger` / `--ds-danger-wash` | 已到期、還沒收到 | `st-unpaid`、`chip--danger` |
| 繳了一部分 | `--ds-warning` / `--ds-warning-wash` | 收了一些，還有餘額 | `st-partial` |
| 待確認入帳 | `--ds-warning` + 虛線前綴（已有 `.st-pending::before`） | 家長說繳了，你還沒按確認 | `st-pending` |
| 已收 | `--ds-success` / `--ds-success-wash` | 錢已確認入帳 | `st-paid` |
| 免費 | `--ds-ink-mute` / `--ds-canvas-soft` | 免費／贈送，不用收 | `chip--muted` |
| 不收了 | `--ds-ink-mute` / `--ds-canvas-soft` | 確認不收，已結案 | 目前誤用 `st-paid`（綠），改灰 |
| 多收 | `--ds-danger` | 舊資料已收超過帳單；新資料不會發生 | `chip--danger` |
| 需核對 | `--ds-info` / `--ds-info-wash` | 資料有衝突，系統不判斷 | 目前用 warning，改 info 以免與「待確認入帳」混淆 |
| 已作廢 | `--ds-ink-mute`（預設摺疊） | 開錯或更正，不算錢 | `chip--muted` |

規則：badge 最多 6 個字；顏色全站一致（Polaris 規則 2）；「待確認入帳」一律不再寫成「已回報付款，待你核對入帳」「家長回報…待你核對入帳」「待核對入帳」三種。

### 2.1 目前同義詞不一致（要收斂）

| 概念 | 現有說法 | 位置 | 統一為 |
|---|---|---|---|
| 沒收到錢 | 未繳 / 未收 / 未結清 / 未付 / 還沒繳費 / 尚未收款 / 未繳費 | `ContractCard.vue:18,26,159,216`；`TuitionCollectionPage.vue:320`；`ContractMoneyState.php:88` | **未繳**（金額欄標題用「未繳」） |
| 收到錢 | 已收 / 已繳 / 已付 / 已收清 / 已記入 / 已繳費 | `ContractCard.vue:31,159,228`；`StudentBillingList.vue:73`；`TuitionCollectionPage.vue:318`；`AccountingLedgerModal.vue:119` | **已收** |
| 部分 | 繳了一部分 / 付了一部分 / 部分已收 / 部分記入 | `courseMoneyState.js:221`；`ContractCard.vue:159,231`；`AccountingLedgerModal.vue:950` | **繳了一部分** |
| 等確認 | 已回報付款，待你核對入帳 / 家長回報…待你核對入帳 / 待核對入帳 / 待確認金額 / 已送出，待你核對入帳 | `courseMoneyState.js:171,255`；`ContractCard.vue:33`；`TuitionCollectionPage.vue:872`；`PaymentReportController.php:771` | **待確認入帳** |
| 金額未定 | 金額待確認 / 還沒開帳單，金額待確認 / 待開單 / 估算 | `courseMoneyState.js:176`；`ContractCard.vue:28`；`TuitionCollectionPage.vue:385` | 「待確認入帳」與「金額未定」是兩件事。金額未定改 **還沒開帳單**。 |
| 撤銷 | 撤銷 / 撤銷收款 / 更正收款 / 已更正 / 已撤銷 / 作廢 / 更正並作廢 | `AccountingLedgerModal.vue:59,165,172,198,950`；`courseMoneyState.js:255` | 收款用 **撤銷收款**；帳單用 **作廢帳單**。「更正」只留給「更正並重開」 |
| 後端狀態 | `已繳費 / 未繳費 / 已確認不收 / 免費（不適用）` | `backend/.../ContractMoneyState.php:86-88` | 後端不得吐主任可見字串；確認有無消費者後移除（先查，R2） |

## 3. 前→後文案表

行號以 `frontend/src/` 為基準。「模式」編號對應 §1。

| # | 檔:行 | 現在 | 改成 | 模式 |
|---|---|---|---|---|
| 1 | `AccountingLedgerModal.vue:43` | 多收 NT$ 1,500。下面「需先處理」會寫是哪一筆，可以撤銷多出來的那一筆。 | 多收 NT$ 1,500（舊資料）。詳情在「需先處理」。 | 5,9 |
| 2 | `AccountingLedgerModal.vue:61` | 這筆沒有可撤銷的收款，請找老闆確認。 | 這筆多收沒有對應的收款，系統無法自動處理。 | 5,9 |
| 3 | `AccountingLedgerModal.vue:591` | 多收的錢沒有記進任何帳單。如果是重複收款，撤銷這筆就好；要保留請找老闆處理。 | 多收的錢沒有記進任何帳單。若是重複收款，按「撤銷這筆」。 | 5,9 |
| 4 | `AccountingLedgerModal.vue:588` | 7/3 匯款 NT$ 21,000 這筆，比帳單多收了 NT$ 1,500 | 7/3 匯款 NT$ 21,000，比帳單多 NT$ 1,500 | 1 |
| 5 | `AccountingLedgerModal.vue:590` | 撤銷這筆 | 撤銷這筆收款 | 4 |
| 6 | `AccountingLedgerModal.vue:47` | 需先處理 | 要先處理 | 3 |
| 7 | `AccountingLedgerModal.vue:970` | 多收，疑似重複收款 | 多收 | 1 |
| 8 | `AccountingLedgerModal.vue:971` | 同一帳單有多筆收款 | 同一張帳單收了多次 | 1 |
| 9 | `AccountingLedgerModal.vue:972` | 帳單金額不一致 | 帳單金額對不上 | 1 |
| 10 | `AccountingLedgerModal.vue:973` | 顯示已繳但仍有餘額 | 標示已收，但還有餘額 | 1 |
| 11 | `AccountingLedgerModal.vue:974` | 顯示未繳但已繳足 | 標示未繳，但金額已收足 | 1 |
| 12 | `AccountingLedgerModal.vue:975-980` | 收款缺少收據／收據缺少收款／收據尚未對到帳單／已確認收據缺少收款／收據收款不在本學生帳本 | 不顯示給主任（沒有收據制度）。合併為一條「紀錄對不上，請回報」+ 一個回報按鈕，細節收進「為什麼？」 | 9（Founder：不開紙本收據） |
| 13 | `AccountingLedgerModal.vue:70` | 收合異常／還有 N 則 | 收起／還有 N 項 | 1 |
| 14 | `AccountingLedgerModal.vue:119-120` | 已記入／未結清 | 已收／未繳 | 2 |
| 15 | `AccountingLedgerModal.vue:950` | 已記入／部分記入／多收待處理／已更正 | 已收／收了一部分／多收／已撤銷 | 2 |
| 16 | `AccountingLedgerModal.vue:108` | 這門課目前沒有帳單和收款（舊制已繳，或尚未開立）。下面列的是此學生… | 這門課沒有帳單和收款。下面是這位學生的其他紀錄。 | 1 |
| 17 | `AccountingLedgerModal.vue:109` | 目前只有繳費單（依課程估算），還沒有帳單和收款。登記收款並確認入帳後，才會出現在這裡。 | 還沒開帳單，也沒收款。登記收款後會出現在這裡。 | 1 |
| 18 | `AccountingLedgerModal.vue:903` | 撤銷收款會保留稽核紀錄，不會更改帳單應繳金額；帳單開錯需另行作廢並更正。請輸入撤銷原因 | 撤銷這筆收款（帳單金額不變）。請寫原因： | 3 |
| 19 | `AccountingLedgerModal.vue:285` | 其他紀錄（3）：已退回、已撤銷或 0 元 | 已退回／已撤銷（3） | 1 |
| 20 | `ContractCard.vue:18` | 本合約帳單 應收 …・已收 …・未收 … | 應收 …・已收 …・未繳 … | 2 |
| 21 | `ContractCard.vue:28` | 還沒開帳單，金額待確認 | 還沒開帳單 | 1 |
| 22 | `ContractCard.vue:31` | 已收清 | 已收 | 2 |
| 23 | `ContractCard.vue:33` | 家長回報 10/5 已付 NT$ 6,600，待你核對入帳 | 待確認入帳 NT$ 6,600（10/5 家長回報） | 1,2 |
| 24 | `ContractCard.vue:51` | 帳單不會跟著課程金額自動改。金額不同可能是約定調整，不代表帳單有誤；請先確認原約定與應收金額，確認開錯才更正。 | 帳單金額不會隨課程金額變動。 +「為什麼？」展開上面這段 | 7 |
| 25 | `ContractCard.vue:96` | 確認實際收到 X，這筆會算已收。這裡只確認回報金額，不能修改金額；不符請取消並退回回報。撤銷收款不會更改帳單應繳… | 確認收到 NT$ X？確認後算「已收」。金額不對請取消，改按「退回」。 | 1,9 |
| 26 | `ContractCard.vue:104` | 退回只處理這筆待核對回報，不會更改帳單應繳金額，也不代表退款。 | 退回後這筆回到未繳，不是退款。 | 1 |
| 27 | `ContractCard.vue:72` | 上課日暫時無法載入，金額不受影響。 | 上課日載入失敗，金額不受影響。[重試] | 1 |
| 28 | `ContractCard.vue:35` | 退回 | 退回這筆 | 4 |
| 29 | `StudentBillingList.vue:138` | 已回報付款，待你核對入帳 | 待確認入帳 | 3 |
| 30 | `StudentBillingList.vue:16` | 另有金額待確認 NT$ X（N 筆，未計入未收） | 還沒開帳單 NT$ X（N 筆，不在未繳內） | 2 |
| 31 | `StudentBillingList.vue:14` | 本校區到今天未收 | 本校區到今天未繳 | 2 |
| 32 | `StudentBillingList.vue:18` | 本校區目前沒有到期未繳。 | 本校區沒有未繳。 | 1 |
| 33 | `StudentBillingList.vue:142` | 還沒到期／沒有待收款項 | 之後才到期／無待繳 | 2 |
| 34 | `StudentBillingList.vue:4` | {error}。未載入前不顯示金額，避免誤以為都已繳清。 | 載入失敗，金額先不顯示。[重試] | 5 |
| 35 | `MonthlyDraftsPanel.vue:99` | 系統已算好新一期，確認金額和期間沒問題再開立。 | 下一期帳單已備好，核對後開立。 | 1 |
| 36 | `MonthlyDraftsPanel.vue:143` | 請聯絡總部補開。 | 這幾筆缺上一期帳單，請聯絡總部補開。 | 5 |
| 37 | `MonthlyDraftsPanel.vue:151` | 若已不上課，請到課程管理結案。 | 這些課已到期但沒上課。不再上的話，到課程管理結案。（連結直達） | 5,9 |
| 38 | `MonthlyDraftsPanel.vue:130` | 新一期結束日已過，無法在此開立 | 這一期已過，不能開立 | 5 |
| 39 | `MonthlyDraftsPanel.vue:131` | 確認開立 | 開立帳單 | 4 |
| 40 | `MonthlyDraftsPanel.vue:107` | 目前沒有待處理的帳單／這個月份沒有需要開立或補開的月繳帳單。 | 沒有要開的帳單 | 1 |
| 41 | `backend MonthlyRenewalPeriodService.php:38` | 有 N 堂已上課落在合約日期外。請先到月結核對確認堂次與帳務歸屬，再建立新一期。 | 有 N 堂上課日在合約日期之外，先處理這幾堂才能開下一期。[看這幾堂] | 5,9 |
| 42 | `MonthlyBillingReview.vue:58` | 列出月結帳單與上課堂數不符的課程，包含已上課卻沒有對應帳單的月份。先核對，再更正合約與建立帳單。 | 這些月結課程的上課堂數和帳單對不上。 | 1 |
| 43 | `MonthlyBillingReview.vue:77` | 試算尚未成為應收帳單。 | 估算，還沒開帳單。 | 1 |
| 44 | `MonthlyBillingReview.vue:75` | N 堂沒有對應帳單服務期間 | N 堂沒有帳單 | 1 |
| 45 | `MonthlyBillingReview.vue:78` | 核對登錄收款 | 看收款 | 4 |
| 46 | `courseMoneyState.js:171` | 已回報付款，待你核對入帳 | 待確認入帳 | 3 |
| 47 | `courseMoneyState.js:173` | 課已結束，尚未收款 | 已結束，未收 | 3 |
| 48 | `courseMoneyState.js:183` | 需核對：舊資料標記已收，缺付款紀錄 | 需核對（舊資料標已收，查無收款） | 3,10 |
| 49 | `courseMoneyState.js:255` | 待核對入帳／已退回／已撤銷 | 待確認入帳／已退回／已撤銷 | 2 |
| 50 | `courseMoneyState.js:180` | 月結帳單與上課堂數不符，請核對 | 需核對（帳單與堂數對不上） | 3,10 |
| 51 | `courseMoneyState.js:187` | 這門課標記已收，但找不到收款紀錄。已經收過就補登收款；其實沒收就改成未繳。 | 標已收但查無收款。收過了：補登收款。沒收：改成未繳。 | 1,9 |
| 52 | `TuitionCollectionPage.vue:5` | 處理未繳、待核對入帳、確認入帳與續課提醒。 | 看誰沒繳，確認家長回報的款項。 | 1 |
| 53 | `TuitionCollectionPage.vue:14-15` | 照順序完成，不用記入口／先找到對象，再送出回報，最後由主任確認入帳。 | 兩步：登記收款 → 確認入帳 | 1（兩步流程保留） |
| 54 | `TuitionCollectionPage.vue:1360` | 登記繳費回報／家長已付款時先登記回報。 | 登記收款／收到錢先登記。 | 4 |
| 55 | `TuitionCollectionPage.vue:1361` | 確認入帳與收據／核對資料後才建立正式入帳。 | 確認入帳／核對無誤後算「已收」。 | 4（去掉收據） |
| 56 | `TuitionCollectionPage.vue:145` | 未繳與待核對入帳的課會集中在待處理，已收／續課提醒留在其他分類。 | （刪除。標題「今天先處理 N 筆」已足夠） | 1 |
| 57 | `TuitionCollectionPage.vue:248` | 請分開選取未繳或待核對入帳的課，才能進行批次處理。 | 一次只能選同一種：未繳，或待確認入帳。 | 5 |
| 58 | `TuitionCollectionPage.vue:452,459` | 登記已回報 | 登記收款 | 4 |
| 59 | `TuitionCollectionPage.vue:471` | 金額待確認：請先到「已結清 → 待核對」確認金額，未計入未收 | 還沒開帳單。到學生面板開帳單。 | 5,9（原指示的路徑已不存在於 D10 的 2 分頁） |
| 60 | `TuitionCollectionPage.vue:489-491` | 撤銷 | 撤銷收款 | 4 |
| 61 | `TuitionCollectionPage.vue:620` | 「已確認收款筆數」是一筆筆收據；「涵蓋課程數」…若同一課程有多筆收款，請逐筆撤銷錯的那筆，不要刪除原紀錄。 | 刪除整段；統計卡改「收款筆數／課程數」，說明收進「為什麼？」 | 7 |
| 62 | `TuitionCollectionPage.vue:798-801` | 「已結案課程」包含…兩邊統計方式不同／舊制無帳單：…／例外待處理：… | 「為什麼？」展開；卡片只留：已結案／舊資料／要核對 | 7 |
| 63 | `TuitionCollectionPage.vue:796` | 多收待處理 | 多收 | 2 |
| 64 | `TuitionCollectionPage.vue:949` | 此操作不會刪除原收款；系統會建立一筆更正紀錄並保留稽核。請只用於重複入帳、金額錯誤或誤確認。 | 撤銷後這筆不算已收，紀錄會保留。用於重複、金額錯、誤確認。 | 1 |
| 65 | `TuitionCollectionPage.vue:978` | 這筆欠款將轉為歷史（不收了），不再出現在待處理；帳單與收款紀錄保留，並記錄操作人與原因。 | 這筆改成「不收了」，不再列入未繳。紀錄會保留。 | 1 |
| 66 | `TuitionCollectionPage.vue:1005,1013` | 結案後此課程將從催繳名單移除…取消未來排課並放棄這些剩餘額度。若仍要上課，請先取消並改從課程管理排課。 | 結案後不再追蹤。還有 N 堂沒上，會一併取消。仍要上課請先取消。 | 1,9 |
| 67 | `TuitionCollectionPage.vue:891,862` | 只會處理上方待核對入帳的課程；若資料不符，請取消後逐筆檢查。／請先核對…確認後才會變成已收並開收據。 | 只處理上面這 N 筆。確認後算「已收」。 | 1（去收據） |
| 68 | `PaymentEntryModal.vue:80` | 送出後會顯示「已回報付款，待你核對入帳」，你在這裡按「確認入帳」才算收到。 | 送出後是「待確認入帳」，按「確認入帳」才算已收。 | 1 |
| 69 | `PaymentEntryModal.vue:73` | 課程沒填費用不會自動當免費；確定免費才勾選，否則請先到課程管理設定費用。 | 沒填費用不算免費。免費才勾，要收費請先到課程管理設定。 | 1 |
| 70 | `PaymentEntryModal.vue:222` | …這筆金額超過帳單未收餘額。請先核對實收款項與應繳金額，不要為了送出而改小實收金額。帳單金額有誤，請先核對約定費用並走帳務更正… | 超過未繳 NT$ X，不能登記。實收更多：先確認金額，再到學生帳務更正帳單。 | 5 |
| 71 | `PaymentEntryModal.vue:212` | 此課程已有「待核對入帳」回報，不要重複送出。請先核對該回報金額與實收金額；相符才確認入帳，金額不符請先退回，再重新登記。 | 這門課已有待確認入帳的回報。請先「確認入帳」或「退回」，再登記。 | 5,9 |
| 72 | `PaymentEntryModal.vue:171` | 金額為 0 不能登記。請填實際收到的金額；若畫面顯示應繳 0 但課程仍未繳，請到學生帳務核對帳單。 | 金額要大於 0。 | 1 |
| 73 | `PaymentEntryModal.vue:16` | 待開單／尚無帳單 | 還沒開帳單 | 2 |
| 74 | `PaymentAllocation.vue:52` | 送出後會顯示「已回報付款，待你核對入帳」，你在這裡按「確認入帳」才算收到。 | 同 #68 | 1 |
| 75 | `PaymentAllocation.vue:117` | 超過這份合約未繳的 NT$ X，請改成 X 以下 | 最多 NT$ X（這份合約未繳） | 5 |
| 76 | `PaymentAllocation.vue:202` | 都沒有登記成功，沒有任何金額被記下。 | 全部失敗，沒有記下任何金額。 | 1 |
| 77 | `PaymentAllocation.vue:152` | 這份合約已經有一筆待核對入帳的回報，請先確認或退回 | 已有待確認入帳的回報，請先確認或退回 | 2 |
| 78 | `PaymentSlipModal.vue:15` | 繳費通知單預覽 | 繳費單預覽 | 2（全站只稱「繳費單」；`BillingDocument` 同） |
| 79 | `PaymentSlipModal.vue:90` | 金額待確認，請先確認應收金額 | 還沒開帳單，不能產生繳費單 | 5 |
| 80 | `TuitionReportPage.vue:5` | 依已上課堂數 × 費率試算各學生當月學收。 | 本月已上課堂數 × 費率的估算，不等於已收。 | 1（避免與帳務中心「已收」混淆） |
| 81 | `TuitionReportPage.vue:42,62` | 學收合計／月學收 | 本月估算學費 | 2 |

## 4. 投訴證據（A）

來源：triage API，`bug-reports` 全部 448 筆（id 1–448，2026-04-11 起）。只列編號與主題，不含人名與原文。分類依標題與前 330 字描述，由我人工判讀，**一筆可屬多類**；帳務／繳費／繳費單相關約 75 筆（`tuition-collect` 頁 48 筆為主，另散在 course-mgmt、director、students）。10/13（v2 上線後）到 10/30 共約 21 筆，沒有明顯下降。

| 主題 | 筆數 | 報告編號 |
|---|---|---|
| 金額不對（帳務中心、繳費單、課程查找互相不一致） | 19 | 97, 112, 129, 159, 172, 230, 275, 302, 346, 349, 369, 377, 378, 383, 394, 408, 414, 432, 437 |
| 找不到（合約、期間、備註、匯款資訊、繳費單、是哪一筆帳） | 14 | 303, 304, 354, 355, 356, 357, 361, 378, 384, 396, 404, 425, 445, 350 |
| 按了沒反應、卡住、做不到（登記、確認、撤銷、改回未繳） | 11 | 158, 244, 251, 252, 260, 340, 344, 406, 428, 436, 438 |
| 字面看不懂或前後矛盾（舊制無帳單、例外待處理、付款期間待確認、等你確認、已收又未繳、多收） | 10 | 263, 305, 348, 395, 396, 403, 407, 414, 431, 446 |
| 規則缺口（暫停課還列未繳、輔導課顯示未繳、多付想當預收、雜費、免收登記） | 8 | 59, 273, 325, 351, 361, 424, 439, 440 |
| 表格被切掉、要橫向捲動、手機按不到 | 8 | 271, 279, 306, 309, 326, 339, 362, 444 |
| 步驟太多（要跳頁、要出來再進去、共用方案要開多張） | 6 | 249, 264, 371, 398, 405, 442 |
| 想一次看到整體（某月是否收齊、誰該先催） | 2 | 309, 447 |

讀法：
- 「金額不對」最多，但根因在資料與算法（Verdict 在跑，L1/L2 計畫已涵蓋），**本規格不處理**；本規格只確保畫面上同一數字只出現一種說法。
- 「找不到」與「看不懂」合計 24 筆，是本規格的主戰場：它們多半指向舊的表格（`receivables`、已結清卡片）或 drawer 內要捲動才找到的段落。
- 「表格被切掉」8 筆全部發生在舊的待處理表格（`TuitionCollectionPage.vue` receivables 區，約 :290-510），該表格在第 6 節被合併後這類投訴會一起消失。

## 5. 主任最常做的 6 件事（B）

點擊數＝從進入「帳務中心」算起，到事情完成。「須讀句」＝使用者必須讀一段句子才知道下一步的地方。

### T1 看誰沒繳並收款（最高頻）

- 現況：導覽進帳務中心（1）→ 預設「學生」清單 → 點學生列（2）→ drawer 開啟，要在「到今天未繳 / 之後還會到期 / 多收 / 需先處理 / 合約卡 / 帳單表 / 收款紀錄」七段裡自己找（`AccountingLedgerModal.vue:36-290`）→ 底部「登記收款」（3）→ 分配表單（日期、方式、後 5 碼、每份合約金額、備註；`PaymentAllocation.vue:11-52`）→ 送出（4）→ 完成（5）→ 卡片出現待確認 → 確認入帳（6）→ 對話框確認（7）。**7 次點擊，約 5 個決定，須讀句 3 處**（`PaymentAllocation.vue:52`、`ContractCard.vue:96`、`AccountingLedgerModal.vue:43`）。
- 另有第二條入口：舊 `receivables` 表格列上的「登記已回報」（`TuitionCollectionPage.vue:452,459`）→ `PaymentEntryModal`，單合約版表單。同一件事兩種表單，欄位順序不同。
- 成熟產品：
  - Odoo 17：發票上一顆「Register Payment」，彈窗預填未收金額，少收時二選一「Keep open」「Mark as fully paid」（`odoo@17.0 account/i18n/account.pot:11420, 8400, 8919`）。
  - Jackrabbit：家庭頁直接記付款，預設「Apply to Oldest Fees First」，逐筆顯示已套用／未套用（help.jackrabbitclass.com/help/post-individual-payment）。
  - Invoice Ninja：發票列動作「Add Payment」（`invoiceninja@v5.13.51 lang/en/texts.php`；`app/Models/Invoice.php:249-263` 狀態常數）。
  - 共通：**動作的名字就是結果；表單預填；一個入口。**
- 建議流程（目標：登記 3 次點擊，登記＋確認 5 次）：
  1. 清單列右側只放一顆按鈕，名稱隨狀態變：未繳→「登記收款」。
  2. 點它：drawer 開啟並**直接展開分配表單**（已有 `initialAction` 機制，`AccountingLedgerModal.initialAction.test.js`）。
  3. 送出後結果頁加第二顆按鈕「現在確認入帳」（仍是兩個明確步驟，只是不用回到卡片找）。

```
學生            未繳        狀態           
王小明     NT$ 19,500   [未繳]        [登記收款]   <- 一列一個主動作
李小華     NT$  6,600   [待確認入帳]   [確認入帳]
陳大文     NT$  0       [要開帳單]     [開帳單]
```

### T2 確認入帳

- 現況：同 T1 步驟 6–7。另一條：舊表格列「確認入帳」「退回」（`:476-483`）、批次「批次確認入帳」（`:246`）。三處入口。對話框要讀 `ContractCard.vue:96` 約 70 字。
- 成熟產品：Odoo 付款後發票 badge 直接轉 Paid／In Payment（`account_move.py:45-51`）；Stripe 對外部付款在發票頁用「Change invoice status → Paid」一次完成（`docs.stripe.com/invoicing/overview`）。
- 建議：列上「確認入帳」開 drawer 並直接彈確認對話框（2 次點擊：列按鈕、對話框確認）。對話框文案縮成一句（見 #25）。批次保留在清單工具列，移除表格內重複入口。

### T3 開下一期／月結帳單

- 現況：兩條路。(a) 提醒條「本月待開帳單」→ 切本月／下個月 → 勾選 → 「確認開立」→ 對話框「確定開立」＝約 5 次（`MonthlyDraftsPanel.vue:96-162`）。(b) drawer 合約卡「開帳單 NT$」→ 對話框「開立帳單」＝3 次（`ContractCard.vue:29`、`AccountingLedgerModal.vue:298-305`）。兩條都要讀 `:99`、`:143`、`:151` 三句。
- 成熟產品：Invoice Ninja 發票 Draft→Sent 用「Send Invoice」單一動作（`texts.php`）；Stripe 草稿發票 → 「finalize」，狀態詞 draft/open（`docs.stripe.com/invoicing/overview`）。
- 建議：清單上這類學生的 chip＝「要開帳單」，列按鈕＝「開帳單」，直接開 drawer 的開立對話框（2 次點擊）。批次開立保留在清單工具列（多選後「開立 N 張」）。「本月／下個月」變成清單上方月份切換，不再是獨立頁面。

### T4 處理異常（多收、金額對不上、需核對、舊制無帳單）

- 現況：drawer 中「需先處理」卡片，要讀 title + message + detail + hint 四段（`AccountingLedgerModal.vue:47-66, 575-596`）；異常代碼對照 10 種（`:968-980`）；沒有按鈕時叫人「找老闆」（`:61`）。另一條：已結清頁的「舊制無帳單」「例外待處理」標籤與兩段說明（`TuitionCollectionPage.vue:798-801,826-827`）。對應 #446、#348、#428、#438。
- 成熟產品：Invoice Ninja 直接禁止（`over_payments_disabled`: "Company doesn't support overpayments."，`texts.php:3814`）；Akaunting 只報事實加金額（`invoices.php over_payment`）。兩者都不產生「叫使用者找別人」的狀態。
- 建議：每個異常 = 事實一行 + 一顆可按的按鈕（撤銷這筆、補登收款、改成未繳、聯絡總部）；沒有按鈕就不顯示成待辦，只放進「需核對」清單供管理者看（Founder 決定 F1）。收據類異常（:975-980）對主任隱藏，因為不開紙本收據。

### T5 確認某個月是否全部收齊（#447；目前**做不到**）

- 現況：沒有任何畫面回答。「學生」清單的「本校區到今天未繳」是時間切面不是月份（`StudentBillingList.vue:14`）；「收款紀錄」按收款日期篩，合計是已確認收款（`TuitionCollectionPage.vue:612`）；「當月學收」頁（PIN 保護，`lib/pinGate.js:5`）是**已上堂數 × 費率的估算**，卻叫「學收」，主任以為是實收（`TuitionReportPage.vue:5,42`）。
- 成熟產品：Stripe／QuickBooks／Xero 的應收報表都按帳單日期或到期月分組，顯示 Open / Paid 兩欄（Stripe 發票狀態只有 open/paid，`docs.stripe.com/invoicing/overview`；QuickBooks、Xero 本次未驗證）。Invoice Ninja 清單可依狀態 Unpaid/Partial/Paid 篩（`Invoice.php:249-263`）。
- 建議：在「紀錄」頁加月份摘要列：`9 月　應收 X・已收 Y・待確認入帳 W・未繳 Z　[查看未繳的 N 位]`，一鍵帶入清單並篩月份。數字**只能加總 Verdict**，不新算（Founder 決定 F3）。「當月學收」改名「當月估算」並說明「不等於已收」。

### T6 共用方案、多科合收（#442、#249）

- 現況：drawer 中每個成員合約各一張卡，各自「未繳」與「登記這筆」；另有底部「登記收款」；說明靠 `AccountingLedgerModal.vue:76` 一句；追隨成員卡只顯示 `ContractCard.vue:37` 的灰字。結果：兩個未繳、兩顆回報鈕。
- 成熟產品：Jackrabbit 以「家庭」為付款單位，付款一次套到多筆費用（同上）；Odoo 一筆付款可核銷多張發票（payment register 支援多張）。
- 建議：共用方案在 drawer 內合成**一張方案卡**：一個未繳、一顆「登記收款」，成員只列科目與堂數；單據（繳費單）一張。資料欄位 `package_id`、`sharedFollower` 已有。

## 6. 資訊架構合併（C）

現況入口：頂部分頁 3 個（學生／收款紀錄／代收代付）+ 提醒條 4 個（待處理清單／月結要核對／本月待開帳單／已結清）+ 月份 2 個（本月／下個月）+ 另一頁「當月學收」＝**10 個地方**（`TuitionCollectionPage.vue:1159-1170`、`MonthlyDraftsPanel.vue:96-97`、`navigationRegistry.js:10`）。PRD D10 已決定「5→2 分頁」，但提醒條實際上仍是整頁切換，舊 receivables 表格還活著。

建議收斂為 **2 個地方 + 1 個管理者專用**：

| 新位置 | 取代 | 內容 |
|---|---|---|
| 待辦（預設） | 學生、待處理清單、月結要核對、本月待開帳單、本月／下個月 | 單一待辦清單；一個學生一列；列上 chip＋唯一主按鈕；上方篩選 chip：全部／未繳／待確認入帳／要開帳單／需核對／快到期；月份選擇器只在「要開帳單」下出現 |
| 紀錄 | 收款紀錄、已結清、當月學收 | 收款流水、已結清（作為篩選）、月份摘要列（T5） |
| 代收代付（director only，不動） | — | 書籍費等，#439 已解 |

列上 chip 固定集合（與第 2 節詞表一致）：`需核對 > 待確認入帳 > 未繳 > 要開帳單 > 快到期`。一個學生只出現一列，主按鈕依最高優先 chip 決定；其他 chip 仍顯示。

```
[待辦 12]  [紀錄]  [代收代付]                  校區：木柵 v
篩選: 全部 | 未繳 5 | 待確認入帳 3 | 要開帳單 2 | 需核對 1 | 快到期 1
本校區到今天未繳 NT$ 86,400 (5 位)           [匯出]
-------------------------------------------------------------
陳大文   需核對 +未繳   NT$ 4,000        [查看]
李小華   待確認入帳     NT$ 6,600        [確認入帳]
王小明   未繳           NT$ 19,500       [登記收款]
張小美   要開帳單       下一期 NT$ 6,600 [開帳單]
```

處置：

| 項目 | 動作 | 依據 |
|---|---|---|
| 待處理清單（receivables 表） | **移除**（內容併入待辦） | 與學生清單重複；8 筆「表格被切掉」投訴都在此表；兩種登記表單 |
| 月結要核對 | **併入**待辦 chip「需核對」；`MonthlyBillingReview` 的表格保留為 drawer 內的「為什麼需核對」 | 目前是獨立表格＋獨立分頁 |
| 本月待開帳單、本月／下個月 | **併入**待辦 chip「要開帳單」＋月份選擇 | 兩條開立路徑縮成一條 |
| 已結清 | **隱藏**成紀錄頁篩選 | PRD D10 已決定 |
| 當月學收 | **保留頁面、改名「當月估算」**、從紀錄頁連過去；是否併入紀錄頁見 F4 | 只動標題與說明 |
| 收款摘要卡片區（`:103-137` 的「筆提醒／應收／逾期／收款率」） | **移除**，只留清單上方一行「到今天未繳」 | 每張卡都是第二種說法 |
| 步驟引導（`:10-19`、`:1358-1362`） | **移除** | 兩步流程已顯示在按鈕上 |

## 7. 版面調整與 Founder 決定（D）

| # | 內容 | 大小 |
|---|---|---|
| L1 | drawer 頂部「一行結論」：`已收齊` 或 `未繳 X・待確認入帳 Y`；多收獨立一行警示，不與未繳並列（#446、#447） | M |
| L2 | 「為什麼？」展開（用既有 `AtHelpDisclosure`），長說明收進去 | S |
| L3 | 清單列單一主按鈕＋ drawer 以 `initialAction` 直接展開對應步驟；結果頁加「現在確認入帳」 | M |
| L4 | 共用方案單卡（T6） | M |
| L5 | 單一待辦清單合併（第 6 節） | L |

這些**不改**：兩步流程、不顯示內部代碼、禁止多收、一次一校區、不開紙本收據、學生為主清單、不新增算法。

**F1　沒有按鈕可處理的異常怎麼辦？**（#446 類）
- A：drawer 只顯示一行「資料待查」，自動進管理者清單（需新增一條通知）。
- B：維持卡片，只寫事實，不叫人去找誰。
- 建議 **B** 先上（零新功能），A 視後續投訴再決定。

**F2　登記完成後直接出現「現在確認入帳」按鈕。**（#405 要求同一介面完成）
- A：加按鈕，仍是兩個明確步驟，由同一位主任按。
- B：不加，維持回到卡片再按。
- 建議 **A**。不違反「保留兩步」，但等於讓主任確認自己剛登記的款項；若 Founder 要求登記與確認須分人，選 B。

**F3　「某月是否收齊」的數據來源。**（#447）
- A：新增唯讀的月份彙總（對既有每合約 Verdict 按帳單月份加總），M–L 級，需後端新 read API。
- B：只在待辦清單加月份篩選，顯示該月仍未繳的學生；沒有整月彙總數字。
- 建議 **A**；若擔心「第三套算法」，要求 A 的實作只加總 Verdict 輸出並附同源測試。

**F4　「當月學收」頁。**
- A：併入「紀錄」頁月份摘要，移除獨立頁與 PIN 設定。
- B：保留獨立頁，只改名「當月估算」。
- 建議 **B** 先上；A 等 F3 完成後再評估，因為它牽涉 PIN 保護與薪資對帳用途。

**F5　多付的錢（#424 想當儲值）。**證據是 1 筆，且與「禁止多收、無餘額」衝突。**不建議改規則**；維持擋下，錯誤訊息改成事實＋做法：「最多可收 NT$ X（這份合約未繳）。多收的錢請另開下一期。」不需決定，僅列出供知悉。

## 8. 實作切片（E）

排序依「投訴筆數 × 工作量」。每個 PR <700 行，不疊 PR。

| PR | 內容 | 解決的投訴主題（筆數） | 預估 | 風險 |
|---|---|---|---|---|
| R1 | 文案＋詞表統一（第 2、3 節 81 條） | 看不懂 10、找不到 部分 | 300–450 行 | R1 |
| R1b | 後端字串同步（待確認入帳、續期阻擋訊息）；移除 `ContractMoneyState.php:86-88` 後端標籤前先 grep 消費者 | 同上 | <150 行 | R1，需 PHP 測試 |
| F-1 | L1＋L2：drawer 一行結論＋「為什麼？」；異常卡「事實＋按鈕」；隱藏收據類異常 | 矛盾 10、規則 部分 | <500 行 | R2 |
| F-2 | L3：清單列單一主按鈕、drawer 直開、結果頁「現在確認入帳」（需 F2 決定） | 步驟 6、沒反應 11 的一部分 | <600 行 | R2 |
| F-3 | L4：共用方案單卡 | 步驟（#442、#249） | <500 行 | R2 |
| F-4 | L5 第一段：清單加 chip＋篩選，把「要開帳單」「需核對」併入；`MonthlyDraftsPanel` 與 `MonthlyBillingReview` 改為被呼叫的內容 | 找不到 14 | <700 行 | R2 |
| F-5 | L5 第二段：移除 receivables 表、摘要卡片、步驟引導 | 表格被切掉 8 | 淨刪除為主 | R2 |
| F-6 | T5 月份摘要（待 F3） | 整體 2（但是 Founder 直接提的） | 後端＋前端 <700 | R3 若需 migration 則停 |

注意：
- 「金額不對」19 筆與「按了沒反應」多數屬資料或後端問題，**不在這幾個 PR 內**，走 Verdict／不變式測試與逐案修。F-5 移除舊表格後，部分「沒反應」會自然消失（#244、#260、#340、#436 發生在舊表格入口），但要上線後 3 天再判斷。
- 每個 PR 都要附：before/after 截圖（桌機、手機）、更新後的測試清單、版本更新卡。

### 測試策略

- 會因字串改動而失敗的現有測試（改期望，不改行為）：`AccountingLedgerHumanCopy`、`AccountingLedgerSharedPackage`、`AccountingLedgerWaivedAllocation`、`ContractCard`、`ContractCardSlip`、`StudentBillingList`、`PaymentAllocation`、`PaymentEntryModal`、`PaymentSlipModal`、`PaymentSlipPendingConfirmation`、`MonthlyBillingReview`、`TuitionCollection*`（`AmountReview`、`Waive`、`LegacyPaidFlag`、`DocEntries`、`TabsAccessibility`）、`courseMoneyState.test.js`、`DirectorBillingEntryConvergence`。實作時先 `grep -rn "已回報付款\|待核對入帳\|未結清\|已記入\|已收清" frontend/src/components/__tests__ frontend/src/lib`。
- 新增守門測試：掃描 `courseMoneyState.js` 與上述 .vue，出現第 2.1 節被淘汰的詞就失敗。
- F-4、F-5 會讓 `TuitionCollectionTabsAccessibility`、`TuitionCollectionNavigateWiring` 等結構性測試大改，應在同一 PR 內更新，不留綠燈缺口。
- 驗收：Playwright 桌機與手機各一，場景＝#446（多收）、#447（月收齊）、#442（共用方案）、一位主任實測 10 分鐘（沿用 BILLING-UX 計畫的閘門）。

## 9. 附註與限制

- 沒有驗證：Xero、QuickBooks、Square、Teachworks、My Music Staff、Stripe 文案指南（Cloudflare 限流／頁面空白）。上文凡標「本次未驗證」者不可當證據；補讀清單同此。
- 投訴分類為人工判讀，一筆可跨類，數字是量級不是精確統計。
- Invoice Ninja v5 前端是 React（獨立 repo），只引用後端 `lang/` 與 `app/Models/` 字串。
