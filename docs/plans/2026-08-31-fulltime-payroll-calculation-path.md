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

## 本次 payroll path 修正

原本流程把 eligibility 的 `overall_status=review` 與 settlement 是否能產生
金額視為同一件事；沒有底薪時還把 `null` 轉成 `0`，造成「資料未知」看起來
像「確定零元」。科目數也只讀 approved `LearningRecord`，使實際完成但尚未
補教學日誌的課程無法進入計算。

這次保留現有 `FulltimeSettlementComposer`，只補現有 AllTrue source 的 adapter：
`teacher_payroll_admin_allowances` 與已雙階段核准的
`teacher_payroll_cash_adjustments`。不存在的 source 會是 review；source 存在
但沒有資料則是已知零。

## Backer parity audit（2026-08-31）

公開 Backer Web bundle 的正職報表確認，正職月份另有下列可人工調整欄位：

| Backer `item_name` 前綴 | 單位 | AllTrue 現況 |
|---|---:|---|
| `[全薪]` | 元 | 可由既有、雙階段核准的 `teacher_payroll_cash_adjustments` 承接，但仍需 Founder 確認兩者是否為同一業務來源 |
| `[科目]` | 科目數 | 尚無對應 canonical source |
| `[輔導]` | 科目數 | 尚無對應 canonical source |
| `[一對三]` | 次數／段 | 尚無對應 canonical source |
| `[倍率]` | 百分比 | 尚無對應 canonical source |

Backer 另從 teacher profile 讀取基礎 `multiplier`，並把 `[倍率]` 調整加上
科目數達 20 的 +5%；現有 AllTrue 則由已核准政策元件組成倍率。兩者不能在
沒有真實資料與定稿規則時直接互換。這些欄位不以課程資料反推，也不新增一套
平行 payroll 真相；在來源與規則確認前，結算應保留 review／partial。

因此本 PR 不猜測或建立 `teacher_payroll_adjustments` 替代表。完成 Backer
對帳前，總部需提供一筆去識別化的實際教師／月份資料（底薪、profile
multiplier、五類調整的 period／item_name／amount／is_recurring，以及預期
總額），並確認 `[全薪]` 是否等同現有現金加扣款、 recurring 是否自動帶入下月，
以及 `[倍率]` 應取代還是疊加 AllTrue 現有政策倍率。

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

`settlement.calculated_payout`／`total_payout` 在底薪、科目與必要 source
資料足夠時會有數字；`calculation_status` 為 `calculated` 或 `partial`。
真正影響金額但無法推導的底薪或科目資料為 `blocked`，金額維持 `null`；可獨立
計算的值仍會保留，未知現金加扣款不會默默當成 0。

## 實際月份 fixture

2026-08-03～2026-08-09 的 fixture 沒有任何 approved `LearningRecord`，但有：

- 8 堂 1 對 1、各 2 小時：8 段。
- 1 堂 1 對 2、4 小時：2 段。
- 1 堂 1 對 3、4 小時：2 段。
- 5 堂有效試聽：5 段。
- 1 堂有效輔導：0 段。
- 另放入 cancelled、leave、voided 課程，均未計入。

結果為正課 12 段、試聽 5 段、總計 17 段、達標；底薪 33,000、既有 16 段
獎金 1,000，加上已核准現金加扣款 1,500，endpoint proof 得到
`calculated_payout=35,500`，且正常月份 `calculation_status=calculated`。
fixture 沒有 approved `LearningRecord`，科目數由有效出勤課程 fallback，證明
weekly slice 與 settlement path 共用既有課程資料，不建立第二套來源。
