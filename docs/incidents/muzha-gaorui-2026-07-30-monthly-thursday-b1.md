# B1：木柵 高瑞樸 × 楊智超 × 週四 19:00–21:00 數學 — 2026-07-30

> **狀態**：`OWNER_APPROVED_OPTION1` — **等待 Pi 執行**  
>   [`muzha-gaorui-2026-07-30-option1-execution-package.md`](muzha-gaorui-2026-07-30-option1-execution-package.md)  
> **日期**：語境可為 2026-07-28（**星期二**）；目標堂次為 **星期四 2026-07-30** 19:00–21:00  
> **真正 blocker**：本聊天／cloud agent **無 Pi production 連線**，無法代跑寫入；PR #1466 文件本身不會讓課出現  
> **成功後**：執行者貼 before／write／after 到 PR #1466，狀態改 `CONTAINED`

---

## 成功條件（本案唯一 Outcome）

1. production 恰好一筆有效 `ClassSession`：2026-07-30 19:00–21:00  
2. `StudentClassID` = 高瑞樸正確月結數學課  
3. 該課 `TeacherID` = 楊智超（`User.id`）  
4. 老師工作台可見  
5. 出缺勤可點名  
6. 無重複堂次、無舊期 scheduled 殘留、無額外帳務／扣堂變更  

產品契約／Option 2 UX **不是**成功條件。

---

## 為何 refresh／繼續按新增不會好

- 前端僅在畫面已有 `isProjected` 時才叫 `ensure-projected`；沒有預測堂次 → 沒有操作入口。  
- 後端若 `session_date > EndDate` → **「堂次日期超過課程到期日」**（422）。  
- 老師當日自動物化略過超過 `EndDate` 的日期。

**必須**：延長該筆 `StudentClass.EndDate` 涵蓋 2026-07-30 + `ClassSessionMaterializationService::upsertSlot()` 建立唯一一筆。

---

## 最短路徑（Owner 已批准 Option 1）

1. 有 Pi 權限者依 **execution package** 先 backup，再跑單支守衛＋寫入腳本。  
2. 貼 stdout 到 PR #1466；狀態 → `CONTAINED`。  
3. 驗證老師工作台／出缺勤。  
4. **之後**再決定是否做 Option 2 UX（另 PR）。

---

## Code-side 備註（防亂修）

- Builder **不是**「只排四週」；若 `EndDate >= 2026-07-30` 且週四 slot，7/30 應可生成。  
- 老師請查 `User.Name`（`TeacherID` = `User.id`），不要用錯 `Teacher.Name`。  
- `Student` 用 `id`／`name`。  
- `upsertSlot` 對同 SC＋日＋開始時間冪等；若已存在 **cancelled** 列，服務會回傳舊列不新建 → execution package 改走「復原 Status」路徑。

---

## 變更紀錄

| 日期 | 內容 |
|------|------|
| 2026-07-27／28 | B1 調查稿；修正唯讀 `Student.name`／`id` |
| 2026-07-28 | Owner 批准 Option 1；Outcome／blocker 收斂；指向 execution package（本環境無法代寫 production） |
