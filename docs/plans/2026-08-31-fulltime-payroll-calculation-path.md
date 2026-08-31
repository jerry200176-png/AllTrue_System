# 正職薪資計算路徑修正（2026-08）

## 本次先交付：每週16段課

主任端的每週段數以 `ClassSession + StudentSingIn` 為準，不要求
`LearningRecord` 已核准：

- 週期固定為週一至週日。
- 正課以實際 `ClassSession` 時長除以 2 換算段數；同一 session 只算一次。
- 有效試聽每堂固定 1 段。
- 輔導列入可追溯課程明細，但段數為 0。
- `cancelled`、`leave`、voided attendance 與沒有有效點名的課程不計入。
- `total_segments >= 16` 即為達標。

API 的 `components.weekly_16_segments.metrics` 現在提供：
`regular_segments`、`trial_segments`、`total_segments`、
`meets_16_segments`，以及每堂課的 `class_session_id`、日期、時間、類型、
點名狀態與貢獻段數。主任頁面可展開查看構成課程。

## 原本阻斷薪資的原因

原本流程把 eligibility 的 `overall_status=review` 與 settlement 是否能產生
金額視為同一件事：`FulltimeSettlementComposer` 雖然可以取得底薪與部分
獎金，仍只回傳一個被 review 狀態包住的 draft。沒有底薪時還把 `null` 轉成
`0`，造成「資料未知」看起來像「確定零元」。科目數也只讀 approved
`LearningRecord`，所以實際已完成但尚未補教學日誌的課程無法進入計算。

## 現在的路徑

```text
ClassSession + valid StudentSingIn
        ├─ weekly segments (regular / trial / tutoring=0) + course trace
        └─ subject units (approved LearningRecord preferred;
                         attendance fallback for unapproved sessions)
salary profile ───────────────────────────────────────────────┐
                                                              v
policy components ── known core values ──> calculated payout
                   └─ review-only values ─> pending_items (impact=unknown)
```

`settlement.calculated_payout`／`total_payout` 在核心資料足夠時會有數字；
`calculation_status` 為 `calculated` 或 `partial`。真正影響金額但無法推導的
底薪或科目資料則為 `blocked`，金額維持 `null`，不會默默當成 0。

## 實際月份 fixture

2026-08-03～2026-08-09 的 fixture 沒有任何 approved `LearningRecord`，但有：

- 8 堂 1 對 1、各 2 小時：8 段。
- 1 堂 1 對 2、4 小時：2 段。
- 1 堂 1 對 3、4 小時：2 段。
- 5 堂有效試聽：5 段。
- 1 堂有效輔導：0 段。
- 另放入 cancelled、leave、voided 課程，均未計入。

結果為正課 12 段、試聽 5 段、總計 17 段、達標；底薪 33,000 加既有
16 段獎金 1,000，得到 `calculated_payout=34,000`。假日曆缺資料仍列在
`pending_items`，但不阻斷這個已知的核心試算。

目前 AllTrue main 沒有 Backer 對應的現金 `teacher_payroll_adjustments`
資料表；因此 `adjustments` 只列已知的 16 段項目與 composer 接收到的明確
加扣款，未知現金加扣款不應在此分支被假設為 0。若要納入該欄位，下一步應
先建立明確的 AllTrue source/approval contract，再接入 settlement。
