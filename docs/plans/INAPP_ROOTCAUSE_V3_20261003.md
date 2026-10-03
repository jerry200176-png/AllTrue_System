# In-app 回報根治 v3（2026-10-03）

接續 v1（F1–F10，程式同類 bug）、v2（F11，等人狀態沒時鐘）。

## 證據
- Queue dump run `37112802732`：open 31（全 triaged）。其中 6 筆是 feature/ux（290、299、319、322、357、360），#290 從 9/13 開著。
- 同人同頁重複：#359/#363/#364/#365（同一件事 4 筆）；收費頁 #340–#357 一天 8 筆。

## 家族
- **F12 建議沒有出口**：建議走 bug 狀態機，沒有部署可等、沒有提問可計時 → 永遠 triaged。
- **F13 重複回報**：回報視窗看不到自己同頁還在處理的回報。

## 大公司／開源做法（採用的部分）
- Zendesk + Productboard、Intercom：建議回「已記錄到產品清單」後客服單結案；產品清單才是 backlog；上線再通知回報者。客戶回覆會重開。
- GitHub Issues：`Closed as not planned` — 有理由的拒絕也是結案。
- GitHub 新 issue 表單、Canny、Jira Service Management：送出前顯示相似／自己已回報的單（ticket deflection）。

## 做法（不新增狀態機／scheduler／DB 欄位）
- `BugReportService::closeAsLogged`：`closed` + disposition marker + `closed_as_logged` note + 一則公開回覆，同一 transaction，可重跑。
- `reopenIfClosedByTimeout`：`closed_as_logged` 也會因回報者留言回 `triaged`。
- `bug-phase-a-triage.yml` 加 `close_as_logged`；`bug-followup-comment.yml` 允許 `expected_status=closed`（上線通知）。
- `GET /api/v1/bugs/open-on-page`（唯讀、不標已讀）＋回報視窗提示「補充到這筆」。
- SOP：`CHAT_BUG_SYSTEM.md` Phase A 步驟 A6。

## 部署後
1. 重抓 queue dump + 6 筆建議的 detail dump。
2. 290、299、322、357、360：確認 GitHub issue → `close_as_logged`。319 是效能 bug，留著。
3. #359/#363/#364/#365：併成一筆，其餘 `duplicate`。
4. 一週後看同頁重複回報數。

## 驗證
- PHPUnit：結案一次、重跑不重複、缺 issue 拒絕、回報者留言重開（拿掉重開條件測試會 fail）、open-on-page 只回自己同頁未結案。
- Vitest：有同頁未結案 → 顯示並可開啟；沒有 → 不顯示。
- 正式站：第一筆 `closed_as_logged` 用 detail dump 看到 marker＋公開留言；production user-path 未觀測前標 NO。
