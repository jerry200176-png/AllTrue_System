# Incident：新店黃芝琳出缺勤「同一堂變兩堂」（2026-07-18）

> **狀態**：Code fix in PR — production live SQL blocked in cloud agent (no `PI_SSH_KEY` / workflow_dispatch 403).  
> **驗證路徑**：merge → deploy → `php artisan fix:orphan-scheduled-sessions --dry-run` + `repair:duplicate-sessions --case=scheduled-cross-sc` + 老師畫面複查。

---

## 1. Signal

| 項目 | 值 |
|------|-----|
| 回報 | LINE：黃芝琳老師課表上王品方／陳品承各變兩堂 |
| 分校 | 新店（CampusID **9**） |
| 老師 | 黃芝琳（歷史 #1095：User/Teacher **67**） |
| UI | 出缺勤「今日待點名」checkbox 列（非行事曆卡） |
| 時段 | 王品方 13:00–15:00 ×2；陳品承 15:00–17:00 ×2 |
| 錨定日 | 回報當日（約 **2026-07-18**），±7 天掃 |

同族歷史：in-app **#189**（陳品承／黃芝琳／新店）、**R20**（Stop=1 殘留 scheduled）、Epic **#957**。

---

## 2. Cloud-agent access limitation（B1 證據來源）

| 通道 | 結果 |
|------|------|
| SSH `admin@pi.lifenet.com.tw` | Permission denied（環境無 deploy key） |
| `gh workflow run` diagnose | HTTP 403 Resource not accessible by integration |
| Injected secrets | 僅 Gmail / Supabase — **無** `PI_SSH_*` |

因此本案 **無法在 PR 前鎖定 production `cs.id`**。改以：

1. **程式路徑證據**（截圖 UI ≡ `AttendancePage` ← `classifyAttendanceSessionRows` ← `GET /api/v1/class-sessions`）
2. **可重現回歸測試**（跨 SC／Stop=1 雙 scheduled → pending 兩列）
3. **歷史同師同生同校**（#189）
4. **Deploy 後 Pi 唯讀／dry-run** 填入下方「Production IDs」表

---

## 3. Root-cause family（程式證實，待 production IDs 填入）

```
Writers (forward-gen / auto-materialize / renewal)
  → ClassSession per StudentClassID (unique index 只守同 SC)
  → GET /api/v1/class-sessions 未過濾 Stop=1 的 scheduled
  → classifyAttendanceSessionRows key = student_class_id|date|start
  → 出缺勤顯示兩列
TeacherHome key = student_id|date|start → 可能遮住同 bug
```

| 假設 | 條件 | 修復 |
|------|------|------|
| H1 跨 SC 雙 scheduled | 同學生同日同時段、不同 SC、皆 Stop=0 | FE student-slot 去重 + repair `scheduled-cross-sc` |
| H2 R20 孤兒 | 一側 Stop=1 仍 scheduled | API 排除 Stop=1 scheduled + `fix:orphan-scheduled-sessions` |
| H3 Type-A intra | 同 SC 兩 id | 理論上 `uq_class_session_slot` 已擋 |

---

## 4. Production IDs（deploy 後填）

```text
Teacher: id=___ name=黃芝琳
Students: 王品方 id=___ ; 陳品承 id=___
SessionDate: ________

王品方 13:00:
  keep cs.id=___ SC=___ Stop=_ SessionCount=_
  cancel cs.id=___ SC=___ Stop=_ SessionCount=_

陳品承 15:00:
  keep cs.id=___ SC=___ Stop=_ SessionCount=_
  cancel cs.id=___ SC=___ Stop=_ SessionCount=_

Campus 9 scheduled cross-SC groups from date: ___
System-wide orphan Stop=1 scheduled count: ___
System-wide scheduled cross-SC groups: ___
```

唯讀指令（Pi）：

```bash
# 已加入 workflow：Teacher Sign-in Diagnostic → mode=classsession_duplicate
# 或本機（Pi）：
php artisan classsession:audit-duplicates --branch_id=9
php artisan fix:orphan-scheduled-sessions --dry-run
php artisan repair:duplicate-sessions --case=scheduled-cross-sc
```

---

## 5. Code fix scope（本 PR）

1. Attendance classifier：`student_id|date|start` 去重，優先 `course_stop=0`、較新 SC  
2. `ClassSessionController::index`：預設隱藏 `Stop=1` + `scheduled`（`include_stopped_scheduled=1` 可帶回）  
3. Forward gen：跨 SC 同學生同 slot 已有 active → skip + log `cross_sc_slot_conflict`  
4. `repair:duplicate-sessions --case=scheduled-cross-sc`（預設 dry-run）  
5. Business digest：`scheduled_cross_sc` + `orphan_stop_scheduled` 異常計數  

---

## 6. Data repair gate

- **禁止**未 dry-run／未 CEO GO 執行 `--execute --force`  
- 優先跑 `fix:orphan-scheduled-sessions`（Stop=1）  
- 雙 active 約用 `scheduled-cross-sc`：保留 Stop=0 且較新 SC，cancel 另一側  

---

## 7. Verify after deploy

1. `curl -sk https://daan.lifenet.com.tw/api/v1/health`  
2. 黃芝琳出缺勤：王品方／陳品承各一列  
3. Digest / repair dry-run 數字下降或為 0  
4. In-app 白話回覆（禁欄位名）
