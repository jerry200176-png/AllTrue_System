# RFC：Branch Health V1（分校健康唯讀看板）

**Status:** Proposed for implementation
**Risk:** T2（跨前後端、唯讀營運資料；不改排課／帳務資料）
**Scope:** `super_admin` 總部視角；主任首頁不重構

## Decision

Branch Health 是 AllTrue 裡的獨立營運模組，不另建第二套產品、資料庫或登入系統。它使用既有分校、學生、課程、堂次、評量與家長回饋資料，提供總部一頁看 21 間分校，點入看單校證據。

它不併入 `DirectorDashboard` 的每日工作佇列。主任首頁只保留目前分校的高優先提醒；總部健康看板則回答「哪間分校需要先看、目前有哪些可驗證訊號」。

## V1 boundary

V1 只做：

- `super_admin` 可讀取啟用中的分校健康摘要與單校詳情。
- 五個維度以紅／黃／綠／待接資料呈現：學生、教學、家長、教師、營運。
- 每個維度顯示資料期間、訊號數、來源說明與下一步導向。
- 伺服器端執行分校範圍與角色授權；前端不作安全判斷。
- 顯示「現況訊號」，不宣稱完整留存率、教師流失率或教學品質分數。

V1 不做：

- 單一總分、跨校排名、獎懲或 KPI 排行。
- 介入任務、owner／SLA 寫入、主管備註或自動通知。
- 資料修復、排課、出缺勤、扣堂、收費或家長資料寫入。
- 歷史趨勢的假造；沒有可靠 snapshot 就顯示「尚無趨勢資料」。

## Data contract

`GET /api/v1/admin/branch-health`：僅 `super_admin`，回傳全部啟用分校摘要。

`GET /api/v1/admin/branch-health?branch_id={id}`：僅 `super_admin`，回傳單一分校詳情；不存在或停用分校回 404。

每個維度都包含：

```json
{
  "status": "green|yellow|red|unavailable",
  "label": "正常|注意|優先處理|待接資料",
  "signals": [{"key": "...", "label": "...", "value": 0}],
  "period": "current|next_7_days|rolling_28_days",
  "source": "...",
  "next_step": "..."
}
```

V1 可驗證訊號：

- 學生：活躍學生、無未來課學生、已付但未排課、耗盡堂數且無後續課。
- 教學：已上課無學習紀錄、排課重疊、停用課程仍有未來堂、未來七天課量。
- 家長：未讀家長回饋 backlog；沒有足夠分母時 reply rate 只顯示資料，不轉成綠燈。
- 教師：未來七天未指派教師堂數、目前啟用教師數；不把登入率冒充教師穩定度。
- 營運：已付未排課、剩餘堂數差異、低餘額／未付款課程。

尚未有可靠來源的教師流失、教師 capacity、家長客訴與真正續班率，必須在後續資料契約完成後才加入狀態規則。

## Status rules

規則放在後端，不由 Vue 自行推導：

- `red`：存在會直接影響家長承諾、課表正確性或資料可信度的訊號。
- `yellow`：有需人工觀察的訊號，但沒有明確資料完整性故障。
- `green`：本次已接入訊號沒有命中紅／黃門檻；不是「分校品質保證」。
- `unavailable`：資料來源尚未接入或分母不足；不得當成正常。

門檻與 period 必須隨 API 一起回傳，讓看板可以解釋「為什麼是這個顏色」。

## Security and privacy

- endpoint 僅允許 `super_admin`；所有分校 ID 由後端從 `Campus` 讀取。
- 摘要只回傳數字、訊號與去識別化 drill-down 計數，不回傳學生姓名、電話、家長內容或教師個資。
- 單校詳情仍只回傳聚合資料；若未來需要人員名單，另開權限與審計決策，不在 V1 偷加。
- API／測試確認非 `super_admin` 不能讀取全校或指定其他分校。

## Rollout and acceptance

先以 super_admin 的「總部營運」入口上線，主任既有頁面維持原狀。驗收至少包含：API authorization、空資料、停用分校、分校隔離、五維度 status contract、桌面／手機無水平溢出、production health/version 與唯讀 smoke。

後續若要做 Branch Recovery，先以看板實際使用與人工 case audit 驗證指標，再另開 T2 ticket 建立 owner／SLA／intervention 資料模型。
