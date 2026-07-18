# Incident：新店黃芝琳出缺勤「同一堂變兩堂」（2026-07-18）

> **狀態**：Code fix in PR — cloud agent 無 Pi SSH／workflow_dispatch。  
> **Deploy 後**：`bash scripts/diagnose-classsession-duplicates.sh` + execution package。

| 項目 | 值 |
|------|-----|
| 老師／分校 | 黃芝琳（歷史 #1095 Teacher **67**）／新店 Campus **9** |
| 症狀 | 出缺勤 pending：王品方 13:00×2、陳品承 15:00×2 |
| UI | `AttendancePage` ← `GET /api/v1/class-sessions` |
| 同族 | #189、R20、#957 |

## Root-cause family

Unique index 只守同 `StudentClassID`；Attendance 曾用 `student_class_id|date|start` 去重（TeacherHome 已用 `student_id`）；index 未隱藏 `Stop=1`+`scheduled`。Forward-gen 可能放大雙約。

| 假設 | 修復 |
|------|------|
| H1 跨 SC scheduled | FE student-slot 去重 + `scheduled-cross-sc` repair |
| H2 Stop=1 孤兒 | API 隱藏 + `fix:orphan-scheduled-sessions` |

## Production IDs（deploy 後填）

```text
王品方 keep cs=___ SC=___ / cancel cs=___ SC=___
陳品承 keep cs=___ SC=___ / cancel cs=___ SC=___
campus9 scheduled_cross_sc=___  system orphan_stop_scheduled=___
```

## Code（本 PR）

Attendance `student_id` 去重；index 隱藏 Stop=1 scheduled；forward-gen cross-SC skip + log；repair case；digest metrics。

執行包：[`2026-07-18-scheduled-cross-sc-execution-package.md`](2026-07-18-scheduled-cross-sc-execution-package.md)
