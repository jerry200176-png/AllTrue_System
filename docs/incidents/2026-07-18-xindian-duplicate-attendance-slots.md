# Incident：新店黃芝琳出缺勤雙列（2026-07-18）

老師黃芝琳（#1095 Teacher 67）／新店 Campus 9：出缺勤 pending 王品方 13:00×2、陳品承 15:00×2。同族 #189／R20。Cloud agent 無 Pi SSH。

**根因族**：跨 SC 同 slot scheduled；Attendance 曾用 `student_class_id` 去重；index 未藏 Stop=1 scheduled。

**Deploy 後**：`scripts/diagnose-classsession-duplicates.sh` → 填 keep/cancel cs.id → [`execution package`](2026-07-18-scheduled-cross-sc-execution-package.md)。

**本 PR**：FE student-slot 去重；API 藏 Stop=1 scheduled；forward-gen cross-SC skip；repair `scheduled-cross-sc`；digest metrics。
