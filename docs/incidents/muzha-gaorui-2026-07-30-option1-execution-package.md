# Execution Package：木柵 高瑞樸 2026-07-30 19:00–21:00 Option 1 containment

> **狀態**：`OWNER_APPROVED` — **權威執行 = GitHub Actions manual workflow**（勿再貼 tinker 到 Pi）  
> **日期校正**：今天語境可為 2026-07-28（**星期二**）；要補的是 **星期四 2026-07-30** 19:00–21:00  
> **關聯**：調查 PR [#1466](https://github.com/jerry200176-png/AllTrue_System/pull/1466) · B1 [`muzha-gaorui-2026-07-30-monthly-thursday-b1.md`](muzha-gaorui-2026-07-30-monthly-thursday-b1.md)  
> **Workflow**：[`.github/workflows/ops-muzha-gaorui-2026-07-30-containment.yml`](../../.github/workflows/ops-muzha-gaorui-2026-07-30-containment.yml)  
> **Script**：[`.github/ops/muzha-gaorui-2026-07-30-containment.php`](../../.github/ops/muzha-gaorui-2026-07-30-containment.php)  
> **Outcome**：production 恰好一筆有效該時段 `ClassSession`，老師工作台可見、出缺勤可點名  
> **非目標**：不改月結 UX、不改 Alert／invoice／扣堂、不 renew、不新建第二筆 `StudentClass`

---

## 0. 為何只重新整理不會好

- 畫面沒有 isProjected 堂次 → 前端不會呼叫 `ensure-projected`。
- 後端 `ensureProjected`：若 `session_date > EndDate` → **「堂次日期超過課程到期日」**（422）。
- 老師當日自動物化也會略過超過 `EndDate` 的日期。

因此必須：**必要時延長 EndDate 涵蓋 2026-07-30** + **`ClassSessionMaterializationService::upsertSlot()`**（或恢復唯一 cancelled）。

---

## 1. 欄位／查詢校正（執行前必讀）

| 實體 | 正確欄位 | 常見錯用 |
|------|----------|----------|
| `Student` | `id`, `name` | ~~`ID`~~ / ~~`Name`~~ |
| `User`（老師；`StudentClass.TeacherID` = `User.id`，G-001） | `Name` | ~~`Teacher.Name`~~（Teacher 表是 `T_Name`，查老師請用 **User.Name**） |
| `StudentClass` | `ID`, `StudentID`, `TeacherID`, … | — |
| 科目 | `SubjectID` → Subject／BaseData「課程」Val；比對含 **數學**／**Math** | — |
| 週四 | ISO weekday **4**；`week`／`week1`…`week6` 之一 = 4，對應 `time*` 為 19:00 | — |

Targets are **hardcoded** in the PHP script（campus／student／teacher／date／slot）。Workflow 不可傳任意 ID。

---

## 2. Owner 執行順序（手機／GitHub App）

Merge 後（workflow 只允許 `refs/heads/main` dispatch）：

1. Actions → **Muzha Gaorui 2026-07-30 Containment** → Run workflow  
2. `mode = dry-run` → 必須看到 `phase=READY_TO_APPLY` 或 `ALREADY_CONTAINED`  
3. 僅當 `READY_TO_APPLY` 且 invariants 正常：  
   - `mode = apply`  
   - `confirm = I_APPROVE_MUZHA_GAORUI_2026_07_30_CONTAINMENT`  
4. 再跑一次 `mode = verify`  
5. 下載 artifact `muzha-gaorui-2026-07-30-evidence`（JSON only；**不含** DB backup）

**不要**讓 apply 在 merge 後自動跑。

---

## 3. Safety properties（相對舊 tinker package 的修正）

| 項目 | 行為 |
|------|------|
| Dispatch-only | 僅 `workflow_dispatch`；無 push／PR／schedule／workflow_run |
| Exact confirm | apply 必須完全等於確認字串 |
| Script checksum | Actions 計算 SHA-256 → Pi `/tmp` 再驗；mismatch → abort |
| Dry-run | production read-only；phase=`READY_TO_APPLY`／`ALREADY_CONTAINED`／`ABORTED` |
| Backup | apply 前 scoped mysqldump（StudentClass／ClassSession／schedules）→ `gzip -t`；**不上傳** artifact |
| Transaction | `lockForUpdate` + commit 前核心 postcondition；失敗 rollback |
| Overlap guard | `StartTime < 21:00` AND `EndTime > 19:00`（學生／老師） |
| Cancelled rows | count===1 才 restore；>1 → `ABORT_AMBIGUOUS_CANCELLED_ROWS` |
| Idempotency | 已有唯一 scheduled → `ALREADY_CONTAINED` no-op |
| Post-verify | commit 後再驗；失敗 → `APPLIED_POSTVERIFY_FAILED` + **DO NOT RERUN APPLY BLINDLY** |
| Billing invariant | Rate／Charge／Paid／settlement_day／monthly_sessions／identity 欄位 before＝after |

---

## 4. 寫入後人工驗證（執行者勾選）

在 `phase=CONTAINED` 且 `ok_unique_scheduled=true` 後：

- [ ] 課程管理：高瑞樸該月結課可見 7/30 19:00 chip  
- [ ] 楊智超老師工作台：可見該堂  
- [ ] 出缺勤：可點名（勿真的亂點扣堂；確認列存在即可）  
- [ ] 無第二筆同 slot  
- [ ] 未改 Rate／Charge／Paid／invoice  

---

## 5. Rollback（人工；禁止延遲整表 restore）

Backup 路徑見 evidence `backup.path`（Pi：`/home/admin/backups/emergency/`）。

**精確 inverse**（優先）：

1. 若 `write.end_date_extended`：把 `StudentClass.EndDate` 改回 `before.end_date`  
2. 若 `write.path=upsertSlot`：將該 `session_id` 設 `Status=cancelled`（勿 DELETE，除非立即用 dump）  
3. 若 `write.path=restore_cancelled`：將該列改回 `cancelled`

⛔ 隔一段時間後整表 `mysql < dump` 可能覆蓋其他 production 寫入。

---

## 6. 變更紀錄

| 日期 | 內容 |
|------|------|
| 2026-07-28 | Owner 批准 Option 1；tinker execution package（PR #1466；本環境無 Pi） |
| 2026-07-28 | 改為 GHA dedicated workflow + 版控 one-shot PHP；修正 overlap／ambiguous cancelled／tx postcondition／checksum |
