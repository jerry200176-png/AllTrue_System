# Incident：新店黃芝琳出缺勤「同一堂變兩堂」（2026-07-18）

> **狀態**：Root cause **LOCKED with production IDs**（2026-07-22 Pi 唯讀 diagnose run 29888205592 / 29888246248）  
> Code fix：PR #1294 deployed 2026-07-18。資料修復：見 execution package（**CEO GO 前禁止 execute**）。

## Signal

| 項目 | 值 |
|------|-----|
| 老師 | User **67** 黃芝琳（另有 User 225 同名帳） |
| 分校 | Campus **9** 新店 |
| 學生 | **89** 王品方；**2144** 陳品承 |
| UI | 出缺勤「今日待點名」checkbox |
| 回報日 | 2026-07-18（王品方 13:00×2、陳品承 15:00×2） |

## Root cause（一句話）

**H1 成立**：同一學生同日同時段存在兩筆不同 `StudentClassID` 的非 cancelled `ClassSession`；unique index 只守同 SC；出缺勤曾用 `student_class_id` 去重 → 兩列。

## Locked IDs — 回報日 2026-07-18

格式：`name|cs.id|SC|Stop|SessionCount|Remaining|TeacherID|date|start|end|status`

### 王品方 13:00–15:00（黃芝琳課表兩列來源）

| cs.id | SC | Stop | SessionCount | Teacher | Status | Note |
|------:|---:|-----:|-------------:|--------:|--------|------|
| **10274** | **1272** | 0 | 9 | 67 | leave | leave |
| **24111** | **2382** | 0 | 8 | 67 | leave | leave（created 07-11 leave cascade） |
| 24536 | 2383 | 0 | 8 | **30** | attended | 系統補建堂次（增加購買堂數）— 另一老師，非黃芝琳 pending |

→ 黃芝琳看到的雙列 = **SC1272 + SC2382** 同 slot（後皆標 leave；回報當下為 scheduled pending）。

### 陳品承 15:00–17:00

| cs.id | SC | Stop | SessionCount | Teacher | Status | Note |
|------:|---:|-----:|-------------:|--------:|--------|------|
| **24112** | **1946** | 0 | 8 | 67 | attended | leave cascade → attended |
| **20205** | **2399** | 0 | 8 | 67 | attended | |

→ **雙 attended**（帳務／扣堂風險高於純 UI）。of-record 建議保留 SC1946（#189 主課），SC2399 為加購批次。

## 未來仍會再爆（王品方／黃芝琳）

| date | start | SCs | cs.ids |
|------|-------|-----|--------|
| 2026-08-08 | 13:00 | 1272, 2382 | 10275, 24033 |
| 2026-08-15 | 13:00 | 1272, 2382 | 14925, 19978 |

（FE 去重已上線可遮畫面；DB 仍雙列 → 需 `scheduled-cross-sc`）

## 全 production 影響（2026-07-22 錨定）

| 指標 | 值 |
|------|-----|
| `scheduled-cross-sc` dry-run actions | **32** |
| 跨分校 scheduled 雙約組（top50 列表） | **32** 組 |
| Campus 9 組數 | 朱以翔×2、周宥均×3、王品方×2 = **7** |
| Stop=1 orphan scheduled | **0**（`fix:orphan-scheduled-sessions` clean） |
| 涉及學生（系統列表） | ≥14（含王品方、朱以翔、周宥均、吳宏逸、吳宛庭、李昀霈…） |
| 涉及分校 CampusID | 2, 9, 11, 13, 16, 17 |

## Code（已上線 #1294）

Attendance `student_id|date|start` 去重；API 藏 Stop=1 scheduled；forward-gen cross-SC skip；digest metrics；repair case。

## Data repair

[`2026-07-18-scheduled-cross-sc-execution-package.md`](2026-07-18-scheduled-cross-sc-execution-package.md) — dry-run 32 筆已貼齊；**等 CEO GO** 再 `--execute --force`。

## Evidence runs

- https://github.com/jerry200176-png/AllTrue_System/actions/runs/29888205592（anchor 07-18）
- https://github.com/jerry200176-png/AllTrue_System/actions/runs/29888246248（anchor 07-22）
