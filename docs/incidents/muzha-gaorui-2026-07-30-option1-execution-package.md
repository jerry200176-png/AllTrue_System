# Execution Package：木柵 高瑞樸 2026-07-30 Option 1（GHA）

> **權威執行**：GitHub Actions manual workflow（**勿再貼 tinker 到 Pi**）  
> **調查證據**：[#1466](https://github.com/jerry200176-png/AllTrue_System/pull/1466)（B1／H1–H6／為何 refresh 無效）  
> **Workflow**：[`.github/workflows/ops-muzha-gaorui-2026-07-30-containment.yml`](../../.github/workflows/ops-muzha-gaorui-2026-07-30-containment.yml)  
> **Script**：[`.github/ops/muzha-gaorui-2026-07-30-containment.php`](../../.github/ops/muzha-gaorui-2026-07-30-containment.php)  
> **目標**：**星期四 2026-07-30** 19:00–21:00；木柵・高瑞樸・楊智超・數學

## Outcome

production 恰好一筆 scheduled ClassSession；老師工作台／出缺勤可見；無重複；不改帳務／扣堂。EndDate 僅在早於 7/30 時延長至 7/30。

## Owner 順序（merge 後；僅 `refs/heads/main`）

1. Actions → **Muzha Gaorui 2026-07-30 Containment** → `mode=dry-run`  
2. 必須 `phase=READY_TO_APPLY` 或 `ALREADY_CONTAINED`  
3. 僅 READY：`mode=apply` + `I_APPROVE_MUZHA_GAORUI_2026_07_30_CONTAINMENT`  
4. `mode=verify`；artifact 僅 JSON（**無** DB backup）

## Safety

Dispatch-only；exact confirm + `ALLOW_PROD_REPAIR=1` + production；script SHA-256 on Pi `/tmp` + trap cleanup；overlap `StartTime<21:00∧EndTime>19:00`；ambiguous cancelled abort；TX postcondition；post-verify fail → `APPLIED_POSTVERIFY_FAILED`／**DO NOT RERUN APPLY BLINDLY**；billing fields unchanged；idempotent `ALREADY_CONTAINED`.

## Rollback（人工）

還原 EndDate（若曾延長）；該 session 設 `cancelled`。禁止延遲整表 restore。

## 變更紀錄

| 日期 | 內容 |
|------|------|
| 2026-07-28 | #1466 tinker package |
| 2026-07-28 | GHA dedicated lane + safety fixes（successor） |
