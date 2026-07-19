# Leave-cascade slot repair — 主任審核包（CSV-first）
**Issue:** [#1342](https://github.com/jerry200176-png/AllTrue_System/issues/1342)  
**Closeout:** [`docs/incidents/leave-cascade-slot-times-closeout-2026-07-19.md`](../incidents/leave-cascade-slot-times-closeout-2026-07-19.md)  
**Workflow:** `.github/workflows/ops-director-leave-hc-pack.yml`
## 誰做什麼
| 角色 | 責任 |
|------|------|
| 主任 | 開啟分校 CSV，逐列填「審核結果」 |
| Ops／Agent | 用 `review_key` 對照表轉成 `--session-ids`，只執行核准列 |
| Founder | **不**閱讀 `class_session_id`、不代替主任審核 |
## 主任 CSV 欄位
| 欄位 | 說明 |
|------|------|
| 審核結果 | 填：`核准修正` / `保留現況` / `需要查證`（預設空白＝唯讀不動） |
| 分校／學生姓名／課程日期／星期 | 識別個案 |
| 目前時間／契約應有時間／差異 | 錯置說明（白話） |
| 堂次狀態 | 請假／已排課 |
| 判定原因 | 為何列為 high-confidence |
| 是否曾請假相關／是否人工修改時段 | 證據 |
| 建議動作 | 系統建議（主任可改） |
| 審核人／審核時間／備註 | audit |
| review_key | 給系統對照用（**不是**課堂內部 ID 說明） |
`class_session_id` 只存在 Ops 檔 `ops-review-key-map.json`，不上主任表。
## High-confidence 判定（程式）
`RepairLeaveCascadeSlotTimes::classifyPlan`：
- `leave` 列且時段等於其他星期契約 → `high_confidence`（`leave_row_foreign_clock`）
- 同課程有 leave 候選，scheduled sibling 同錯 → `high_confidence`（`scheduled_sibling_of_leave_on_same_course`）
- 其餘 reciprocal ≥3 → medium；singleton → needs_review
## 產出位置
Actions artifact `director-leave-hc-and-evidence`：
- `director-leave-hc/director-review-<campus>.csv`（含學生姓名，勿 commit 進 git）
- `director-leave-hc/ops-review-key-map.json`
- `director-leave-hc/director-pack-summary.json`
傳遞：最低成本 = 下載 artifact → 分校 CSV 寄給／交給該校主任（Gmail／既有營運管道／Sheet 匯入皆可）。不大建 UI。
## 核准 → 執行
1. 主任交回填好的 CSV。
2. Ops：
```bash
python3 scripts/leave-cascade-director-decisions-to-allowlist.py \
  --decisions-csv=/path/director-review-filled.csv \
  --map-json=/path/ops-review-key-map.json \
  --out-allowlist=/tmp/allowlist.txt \
  --out-audit=/tmp/leave-hc-audit.json
```
3. 僅當 allowlist 非空：
```bash
ALLOW_PROD_REPAIR=1 php artisan repair:leave-cascade-slot-times \
  --execute --force \
  --session-ids="$(cat /tmp/allowlist.txt)" \
  --snapshot=/tmp/leave-slot-snapshot.json
```
空白／保留／查證 → **不寫入**。`--execute` 無 `--session-ids` 會失敗（測試守護）。
## 安全約束
- `selected`／審核結果預設不核准。
- 禁止 `--execute --force` 掃全庫。
- Snapshot before/after；可依 snapshot 回滾時段。
