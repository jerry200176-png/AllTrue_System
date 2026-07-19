# TD-059 production audit — 2026-07-19

**Issue:** [#1343](https://github.com/jerry200176-png/AllTrue_System/issues/1343)  
**Runs:** `29685602058`（初審）＋ `ops-director-leave-hc-pack` FN 探針  
**Decision:** **B — Keep open as monitored risk**（不 Close、不 Implement schema）

## Metrics

| Metric | Value | 口徑 |
|--------|------:|------|
| multi_member_packages | 46 | `StudentClass` PackageID>0 Stop=0 GROUP BY HAVING COUNT>1 |
| bound active courses | 112 | same filter, row count |
| partial_minute_deducts | 0 | `session_deduction_ledger.minutes>0` + event deduct + package member |
| partial_minute_reverses | 0 | same + event reverse |
| makeup-tagged partial deducts | 0（FN run） | source/note like makeup/補課 |
| makeup-tagged ClassSession on members | FN run | Status/Note 補課標記 |
| whole-session null-minutes deducts | FN run | minutes IS NULL（#613 前／整堂路徑） |

**時間範圍：** all-time（非近 N 日）— 查得到的 ledger 全歷史。

## 路徑覆蓋 vs false negative

| 路徑 | 覆蓋？ | FN 風險 |
|------|--------|---------|
| 加長補課（minutes>perSession） | 有 minutes 欄才看得見 | 舊資料 null minutes → 無法辨識 |
| 縮短補課 | 同上 | 同上 |
| reverse | reverse+minutes 計數 | reverse 後淨額 0 仍可在 reverse 列看到 |
| cancel | 視是否寫 reverse | 未寫 ledger 則漏 |
| manual adjust | 僅當 minutes 非 null | 常漏標記 |
| refund | 通常不經 package minute ledger | 可能漏 |
| shared-student | multi_member=46 | 已計 exposure |

## 為何不是 A（Close）或 C（Implement）

- **非 A：** 46 組共用包使用中；首次部分分鐘路徑隨時可能出現；無自動 alert 前不關單。  
- **非 C：** 尚無實際 drift／部分分鐘命中 → 不做 migration。

## Monitor（低成本 — 必須可執行）

| 項目 | 值 |
|------|-----|
| Workflow | `.github/workflows/ops-td059-monitor.yml` |
| 頻率 | 週一／四 10:30 Asia/Taipei + `workflow_dispatch` |
| Owner | platform-ops |
| Escalate | `partial_minutes_signal > 0` → 留言 #1343 + P1 label + 去識別 JSON artifact |
| Quiet | 無異常 **不**留言（避免每日噪音） |
| Blind spot | `null_minutes_deduct_blind_spot`（歷史整堂／pre-#613）— 計入 metric 但不自動當 drift |
| 禁止 | 自動 schema migration |

證明：merge 後手動 `workflow_dispatch` 一次，artifact `td059-monitor` 必須出現；clean 時 Issue 無新留言。
