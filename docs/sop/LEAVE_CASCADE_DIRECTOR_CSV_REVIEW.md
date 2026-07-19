# Leave-cascade slot repair — 主任審核包（CSV-first）

**Issue:** [#1342](https://github.com/jerry200176-png/AllTrue_System/issues/1342)  
**Tracker:** [`operations/closeout/leave-hc-campus-review-tracker.json`](../../operations/closeout/leave-hc-campus-review-tracker.json)  
**PII check:** [`docs/incidents/leave-hc-pii-artifact-security-2026-07-19.md`](../incidents/leave-hc-pii-artifact-security-2026-07-19.md)  
**Workflows:** pack `ops-director-leave-hc-pack.yml` · tracker `ops-leave-hc-review-tracker.yml` · repair `ops-leave-cascade-repair.yml`

## 誰做什麼

| 角色 | 責任 |
|------|------|
| 各校主任 | 填分校 CSV「審核結果」；不接觸 session ID |
| Ops | 交付 CSV、更新 tracker、轉 repair bundle、手動 dispatch repair |
| Founder | **不**代審 session ID |

**Artifact 已生成 ≠ 交付完成。** 每校必須有 owner、交付／回覆期限、計數與最後追蹤時間。

## 四校任務（SLA）

| 分校 | 候補 | Owner | 交付 | 回覆 |
|------|-----:|------|------|------|
| 大直 (3) | 7 | 大直主任 + platform-ops | 2026-07-20 17:00+08 | 2026-07-22 18:00+08 |
| 新莊 (11) | 9 | 新莊主任 + platform-ops | 同上 | 同上 |
| 新莊中平 (13) | 2 | 新莊中平主任 + platform-ops | 同上 | 同上 |
| 新店 (9) | 1 | 新店主任 + platform-ops | 同上 | 同上 |

- 逾期提醒：2026-07-23 09:00+08（tracker workflow 留言 #1342）  
- 最終 defer：2026-07-25 18:00+08（未回覆維持不修改，標記 `defer_unreplied`）  
- 計數欄：候補／核准／保留／查證／未回覆／最後追蹤 — 見 tracker JSON

## 主任 CSV 欄位

審核結果：`核准修正` / `保留現況` / `需要查證`（空白＝唯讀不動）。含姓名的 CSV **只在** Actions artifact（retention **14 天**）或受控營運管道。

## 核准 → repair bundle → 受控執行

1. 主任交回填好 CSV（**禁止** commit／貼 Issue／PR）。  
2. Ops 建 bundle（fail-closed）：

```bash
python3 scripts/leave-cascade-build-repair-bundle.py \
  --decisions-csv=/secure/path/director-review-filled.csv \
  --map-json=/secure/path/ops-review-key-map.json \
  --hc-snapshot-json=operations/closeout/leave-hc-campus-review-tracker.json \
  --campus-id=3 \
  --approver='director:campus-3' \
  --out-bundle=/secure/path/repair-bundle-campus-3.json
```

3. 手動 dispatch `ops-leave-cascade-repair.yml`：  
   `mode=dry-run` → 確認 `planned_modifications` →  
   `mode=execute` + `confirm_environment=PRODUCTION` + `confirm_phrase=I_APPROVE_LEAVE_HC_REPAIR` + bundle JSON + expected count。

Bundle 含：allowlist、counts、approver、timestamp、campus、checksums、expected before/after、execution audit ID。  
執行前驗證：HC snapshot 來源、明確核准、before 未變、契約 slot 未變、無新例外、無重複／跨校、核准數一致 — 任一失敗 fail closed。

**禁止**只靠人工貼：

`ALLOW_PROD_REPAIR=1 php artisan repair:leave-cascade-slot-times --execute --force --session-ids=...`

## Post-repair exit gate

`EXIT_GATE_JSON` / `REPAIR_RESULT_JSON` 必須滿足：`repaired`＝有效核准（或 idempotent `unchanged`）、`non_approved_touched=0`、逐筆 skip/fail 可解釋。通過後才可關 #1342。Medium／needs-review 非本 Issue 阻塞。

## PII 紅線

- Repo 只留 redacted summary + tracker。  
- 姓名 CSV／filled CSV／ops map 不上 git、不上 Issue。  
- Actions log 不印姓名或完整 CSV。  
- 轉換後清除本機／runner／Pi 暫存。
