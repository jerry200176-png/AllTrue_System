# 新店 游喨鈞 星期二 → COCO「轉不過去」核查（2026-07-27）

> **狀態**：Fact-check **CONFIRMED**；根因 **LOCKED（行事曆 context）**  
> **來源**：主任口頭反映（尚無專用 in-app bug 單）  
> **Evidence**：GitHub Actions diagnose runs `30268656161` / `30268832383` + code diff CM vs Calendar

## 結論（一句話）

**有這件事。** 游喨鈞確為新店 Coco 正班生；7/28 星期二 14:00 被設成鄒宇旻代課。  
**課程管理「排回主課老師」正常**；**行事曆有問題**——代課挑選器把「正班老師」誤設成畫面上的有效授課老師（代課），導致「回正班老師」按鈕不出現／正班標示錯誤。

## Production 事實

| 項目 | 值 |
|------|-----|
| 分校 | Campus **9** 新店分校 |
| 學生 | id **41** 游喨鈞 |
| 正班課程 | StudentClass **#2366**，TeacherID **70** Coco（active），週二 14:00 |
| 代課例外 | schedules **#5675/#5676** → 鄒宇旻 **202**（2026-07-28） |

## 根因（行事曆）

| 路徑 | `original_teacher_id`（正班） | `current_teacher_id`（本堂） | 「回正班老師」 |
|------|------------------------------|------------------------------|----------------|
| 課程管理 `openSubstituteV2FromEdit` | `course.teacher_id`（合約） | `form.teacher_id`（本堂有效） | ✅ 可顯示 |
| 行事曆拖曳（修前） | 卡片 `teacher_id`（已被代課覆蓋） | 同上 | ❌ 兩者相同 → 隱藏 |
| 行事曆點堂開啟（修前） | `modalForm.teacher_id`（合約） | **未傳** | ❌ `current=0` → 隱藏 |

修復：行事曆對齊課程管理——從 raw `courses` 取合約老師；點堂時寫入 `current_teacher_*`。

## 帳號注意

| User id | Name | status |
|---------|------|--------|
| 58 | COCO | **inactive** |
| 70 | Coco | **active**（正班） |

## 下一步

1. 合併本修後：行事曆對已代課堂應出現「回正班老師」，拖回 Coco 欄應帶正確正班 context。
2. 現場用 7/28 游喨鈞驗證；資料仍可用課程管理排回（已確認可用）。
