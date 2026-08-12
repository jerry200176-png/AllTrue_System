# RFC — 堂次建立與購買權利邊界

> Status: Proposed; P0 repair implementation included, prevention phases require separate runtime GO.
> Date: 2026-08-12
> Related: ADR-006, RFC_COURSE_CONTINUITY, R82–R84

## 現場事件與 P0 決策

木柵張正甯、張正樂各購買 8 堂，8/1 經負責人確認為請假補課；8/5 是實際第 9 次上課，應改由新一期 8 堂批次承接第 1 堂。

P0 不重建或刪除 8/5 的課：保留同一個 `ClassSession`、日期時間、點名、評量與扣堂事件，只把這些資料的 `StudentClassID` 原子轉至新批次。發票、付款、收據與學生身分不得更動。

執行前必須有 dry-run 與 JSON snapshot；正式寫入必須是單一 transaction，留下 `session_entitlement_transfers` 稽核列；執行後必須核對：

- 舊批次 8/8，新批次 1/8；兩批計數與權威扣堂計算一致。
- `ClassSession`、`LearningRecord`、`StudentSingIn`、`session_deduction_ledger` 全部指向新批次。
- 同日同時段沒有重複堂。
- 回滾前若關聯資料已漂移，必須拒絕回滾，不可覆蓋後續人工處理。

## 根因

目前只有課程管理 `addSession` 入口檢查堂數上限。底層唯一建堂服務 `ClassSessionMaterializationService::upsertSlot()` 專注於同時段冪等，沒有接收「為何建堂」及 entitlement 決策；點名、評量、補登、請假補尾、排課生成等多個入口都可呼叫它。因此 UI 顯示超額只能事後警示，不能形成跨入口的伺服器保證。

不能直接以 `ClassSession` 數量一刀切：請假／取消列不消耗，補尾可能合理增加物化列，歷史補登必須保留實際上課；`materialization != consumption` 仍是 ADR-006 的必要邊界。

## 目標架構

所有建堂命令必須提供用途 `intent`：

| intent | 例子 | entitlement 政策 |
|---|---|---|
| `contract_schedule` | 新課／續課排程 | 不得產生 uncovered occurrence；要求確認或減少排程 |
| `leave_tail` | 請假保留日期後補尾 | 允許新增物化列，但 coverage 只在原請假堂釋放後重配 |
| `reschedule` | 既有堂調課 | 移動既有 occurrence，不增加 entitlement demand |
| `attendance_backfill` | 歷史確實上課補登 | 不阻止事實紀錄；若無 coverage，進 P0 exception queue |
| `manual_extra` | 主任主動加課 | 有餘額才建立；不足時主 CTA 是「建立新批次並承接」 |
| `reconcile` | 系統修復缺物化 | 僅補已存在 commitment 且有 coverage 的 occurrence |

伺服器建立前由 `SessionEntitlementPolicy` 回傳 `allow`、`move_existing_future`、`require_new_batch` 或 `record_exception`。UI 只呈現決策，不自行計算或繞過。

## 分期

1. P0：具名修復命令、snapshot、稽核、verify、rollback；修正兩位學生。
2. P1：所有 materializer caller 強制傳 `intent` 與 actor；缺 intent 先記 telemetry，CI 禁止新增匿名 caller。
3. P2：啟用伺服器 entitlement policy；`manual_extra` 超額回傳一致的 409 reason code，並提供「建立新批次並承接本堂」原子 API。
4. P3：啟用 ADR-006 coverage lifecycle；購買批次只提供 coverage，不再靠 `ClassSession` 排序推測第幾堂。
5. P4：exception queue、夜間 reconcile、分校／入口／intent 指標與告警。

## 上線與停止條件

P0 只有在 migration、targeted test、全 CI、部署 identity、正式 dry-run、snapshot、execute、verify 與 UI read-back 都通過後才算完成。任何學生／科目不一致、重複時段、共用方案、計數不一致或關聯資料漂移都必須停止，不得 `force` 繞過 domain guard。

P2 先 shadow 七天；若誤擋任何 `leave_tail`、`reschedule` 或合法歷史補登，停止 hard block，保留 telemetry 並回到規則修正。
