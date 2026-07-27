# 新店 游喨鈞 星期二 → COCO「轉不過去」核查（2026-07-27）

> **狀態**：Fact-check **CONFIRMED**（production 唯讀）  
> **來源**：主任口頭反映（尚無專用 in-app bug 單）  
> **Evidence**：GitHub Actions `Coco Transfer Diagnose (push)` runs `30268656161` / `30268832383`

## 結論（一句話）

**有這件事。** 游喨鈞確為新店 Coco 正班生；明天（2026-07-28）星期二 14:00 堂次目前被設成鄒宇旻代課，所以畫面上不在 Coco 欄，主任要「轉給／轉回 COCO」時會撞上這筆代課例外。

## Production 事實

| 項目 | 值 |
|------|-----|
| 分校 | Campus **9** 新店分校 |
| 學生 | id **41** 游喨鈞 |
| 正班課程 | StudentClass **#2366**，TeacherID **70** Coco（active），週二 `week=2` 14:00，`Stop=0` |
| 明日堂次 | ClassSession **#22359**，2026-07-28 Tuesday 14:00–16:00，`scheduled` |
| 代課例外 | schedules **#5675** `rescheduled`（Coco）+ **#5676** `scheduled` teacher=**202 鄒宇旻**（anchor 5675） |
| 有效授課老師 | **鄒宇旻**（非 Coco） |
| 同日同槽其他人 | 無（該時段僅此堂） |

## 帳號注意

| User id | Name | status |
|---------|------|--------|
| 58 | COCO | **inactive**（舊帳） |
| 70 | Coco | **active**（現行正班） |

若挑選器選到 inactive `COCO(58)` 而非正班 `Coco(70)`，會走「換成另一位老師」路徑而非「回正班」，也可能失敗。

## Bug 單狀態

- **無**標題含「游喨鈞」的 in-app bug。
- 相關歷史（已結）：#201/#202「轉不過去／衝堂」、#88/#90「回正班老師回不去」、#207 陳宇斯／Coco 換老師。
- 目前 open：#208、#209（與本案無關）。

## 下一步（需批准才做）

1. 現場重現：行事曆選 7/28 游喨鈞 →「回正班老師」或拖到 Coco，記錄錯誤訊息／HTTP。
2. 若僅資料修正：可走代課還原（選正班 Coco=70 或「回正班老師」）；**禁止未批准直接改 production schedules**。
3. 若 API 失敗：再進 BUG B1（對照 §R72–R74、restoreOriginalTeacherFromSubstitute）。

## 唯讀限制

本檔僅記錄診斷；臨時 workflow `coco-transfer-diagnose-push.yml` **勿 merge 進 main**。
