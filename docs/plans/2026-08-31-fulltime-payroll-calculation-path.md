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

## 本 PR 與薪資結算的邊界

這個 vertical slice 只負責主任查看每週段數與課程構成；它不新增另一套
payroll system，也不改底薪、倍率、加扣款、科目獎金或結算鎖定規則。
`weekly_16_segments` 的狀態與其他 payroll review／bonus component 解耦，
因此已知段數可以獨立呈現。

原本正職薪資 calculation path 的 root-cause 工作會在後續 stacked PR
繼續處理：保留現有 `FulltimeSettlementComposer`，接通既有的薪資來源，並
讓真正未知的欄位維持 review／pending，而不是把未知值當成零。

## Backer 正職結算欄位對照（保存頁面證據）

已從保存的 Backer 正職結算頁面結構確認欄位順序與可見語意：

| Backer 欄位 | 觀察到的意義 |
|---|---|
| 老師姓名／固定底薪 | 教師與本薪來源；AllTrue 頁面不顯示固定底薪欄，但結算仍保留本薪來源 |
| 正課科目數 | 正課核薪科目數，可用小數 |
| 輔導＋試聽科目數 | 輔導與試聽核薪科目數，可用小數 |
| 核薪總科目數 | 正課＋輔導／試聽 |
| 一對三總計 | 一對三核薪數量／段數 |
| 科目數獎金／一對三獎金 | 倍率前的兩種獎金 |
| 教師倍率 | 100% 加上畫面列出的倍率項目 |
| 倍率後獎金 | `(科目數獎金＋一對三獎金) × 教師倍率`，畫面示例採整數元 |
| 加扣款 | 可新增具名稱的正負金額，例如全勤、16段課、勞健保自付額 |
| 總發放金額 | 本薪＋倍率後獎金＋加扣款 |

保存頁面的實際列可驗算：`33,000 + 1,364 + 2,000 + 4,000 - 1,228 =
39,136`。倍率列同時顯示 `100%` 與各項 `+%`，因此已確認倍率項目在
Backer 畫面是相加後再套用，不是把每項獎金分別乘一次。既有
`teacher_payroll_cash_adjustments` 可承接已核准的具名正負金額；不另建
`teacher_payroll_adjustments` 替代表。

目前仍不能從頁面證據推定的部分，維持明確分類：

1. **AllTrue 尚未收集的事實（case 2）**：Backer 的人工「調整正課／調整輔導／調整一對三」數值、任意調整項目的 `item_name`／`is_recurring` 及其歷史版本，AllTrue 沒有同形 canonical source。
2. **Backer 規則未知（case 3）**：`[全薪]` 是否是覆蓋本薪而非一般加扣款、人工倍率與自動倍率的取代／疊加關係，以及 recurring 是否自動帶入下月。這些不能由課程或現有現金調整資料反推。

因此現有 PR 已修正可確定的跨分校 adapter；已知來源完整時會產生完整金額，
上述真正缺資料仍只進入 review／partial，不會被當作零元。

## 本 PR 的資料路徑

```text
ClassSession + valid StudentSingIn
        └─ weekly segments (regular / trial / tutoring=0) + course trace
           ├─ regular: actual duration / 2
           ├─ trial: one fixed segment
           └─ tutoring: zero segments
```

如果有效出勤資料或正課時長不足，週段數會明確標示 review；不會以排課、
未核准 LearningRecord 或固定 2 小時 fallback 猜測。

## 實際月份 fixture

2026-08-03～2026-08-09 的 fixture 沒有任何 approved `LearningRecord`，但有：

- 8 堂 1 對 1、各 2 小時：8 段。
- 1 堂 1 對 2、4 小時：2 段。
- 1 堂 1 對 3、4 小時：2 段。
- 5 堂有效試聽：5 段。
- 1 堂有效輔導：0 段。
- 另放入 cancelled、leave、voided 課程，均未計入。

結果為正課 12 段、試聽 5 段、總計 17 段、達標；這個 PR 只驗證段數與課程
追溯，不把這個 fixture 的數字延伸宣稱為完整薪資結算結果。
