# B1：木柵 高瑞樸 × 楊智超 × 週四 19:00–21:00 數學 — 2026-07-30

> **狀態**：`OWNER_APPROVED_OPTION1` — **execution lane = GitHub Actions manual workflow**（successor PR；cross-link [#1466](https://github.com/jerry200176-png/AllTrue_System/pull/1466)）  
> **權威執行路徑**：[`ops-muzha-gaorui-2026-07-30-containment.yml`](../../.github/workflows/ops-muzha-gaorui-2026-07-30-containment.yml) + [`.github/ops/muzha-gaorui-2026-07-30-containment.php`](../../.github/ops/muzha-gaorui-2026-07-30-containment.php)  
> **日期**：語境可為 2026-07-28（**星期二**）；目標堂次為 **星期四 2026-07-30** 19:00–21:00  
> **成功後**：workflow evidence JSON（before／write／after）→ 狀態改 `CONTAINED`

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

**必須**：必要時延長該筆 `StudentClass.EndDate` 至 2026-07-30 + `ClassSessionMaterializationService::upsertSlot()`（或恢復唯一 cancelled 列）。

---

## 最短路徑（Owner 已批准 Option 1）

1. Merge 含 workflow 的 successor PR（本檔所在 PR）。  
2. Actions → **Muzha Gaorui 2026-07-30 Containment** → `mode=dry-run`（須見 `READY_TO_APPLY` 或 `ALREADY_CONTAINED`）。  
3. 僅在 `READY_TO_APPLY` 時再跑 `mode=apply` + exact confirm。  
4. 再跑 `mode=verify`。  
5. 細節見 [`muzha-gaorui-2026-07-30-option1-execution-package.md`](muzha-gaorui-2026-07-30-option1-execution-package.md)。

**禁止**：把資料修復塞進 `deploy.yml`；merge 後自動 apply；tinker `--execute` 巨型 quoting。

---

## Code-side 備註（防亂修）

- Builder **不是**「只排四週」；若 `EndDate >= 2026-07-30` 且週四 slot，7/30 應可生成。  
- 老師請查 `User.Name`（`TeacherID` = `User.id`），不要用錯 `Teacher.Name`。  
- `Student` 用 `id`／`name`。  
- `upsertSlot` 對同 SC＋日＋開始時間冪等；若已存在 **cancelled** 列，服務會回傳舊列不新建 → script 改走「復原 Status」路徑；**多筆 cancelled → ABORT**。  
- 學生／老師衝突採 **時間 overlap**（`StartTime < 21:00` 且 `EndTime > 19:00`），不可只比對 StartTime=19:00。

---

## 變更紀錄

| 日期 | 內容 |
|------|------|
| 2026-07-27／28 | B1 調查稿；修正唯讀 `Student.name`／`id`（PR #1466） |
| 2026-07-28 | Owner 批准 Option 1；execution package（tinker）建立 |
| 2026-07-28 | Successor：改為 GHA `workflow_dispatch` + 版控 one-shot PHP（checksum／backup／transaction／post-verify） |
