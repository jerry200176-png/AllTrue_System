# CEO Dashboard — 2026-07-19 Round 3（#1342 最後一哩）

## 1. 四校主任審核任務與 owner

| 分校 | 候補 | Owner | 交付 | 回覆 | 狀態 |
|------|-----:|------|------|------|------|
| 大直 | 7 | 大直主任 + platform-ops | 07-20 17:00+08 | 07-22 18:00+08 | awaiting_delivery |
| 新莊 | 9 | 新莊主任 + platform-ops | 同上 | 同上 | awaiting_delivery |
| 新莊中平 | 2 | 新莊中平主任 + platform-ops | 同上 | 同上 | awaiting_delivery |
| 新店 | 1 | 新店主任 + platform-ops | 同上 | 同上 | awaiting_delivery |

追蹤：`operations/closeout/leave-hc-campus-review-tracker.json` + 每日 `ops-leave-hc-review-tracker.yml`（逾期提醒／最終 defer）。空白＝不寫入；artifact ≠ 交付完成。

## 2. PII／artifact 安全檢查

見 `docs/incidents/leave-hc-pii-artifact-security-2026-07-19.md`。  
結論：private repo OK；姓名 CSV 僅 artifact（retention **14d**）；repo 僅 redacted；禁止 filled CSV／map 進 git／Issue；log 不印姓名；`.gitignore` 補強。

## 3. Repair bundle 與 execution gate

- Builder：`scripts/leave-cascade-build-repair-bundle.py`（fail-closed）  
- 受控執行：`ops-leave-cascade-repair.yml`（manual dispatch、dry-run、PRODUCTION 確認、phrase、backup、allowlist-only、exit gate artifact）  
- Artisan：`--bundle` + `--verify-exit-gate` + per-row `REPAIR_RESULT_JSON`  
- **禁止**恢復 re-scan-and-write；**禁止**只靠手貼 `--session-ids`

## 4. #1342 審核進度

候補 19／核准 0／未回覆 19。待 Ops 交付四校 CSV 並收回回覆。Medium 不阻塞。

## 5. #1343 monitor 是否實際啟用

`ops-td059-monitor.yml`：週一／四排程 + `workflow_dispatch`；clean 不留言；`partial_minutes_signal>0` 才升 P1 + 去識別 evidence。Owner：platform-ops。Merge 後 dispatch 一次證明可跑。

## 6. 下一個 verified P0／P1

|#1342 等主任|不占 implementation WIP|  
|#1292|已 MERGED — 不重開|  
|#1262|已關閉 — 不重開|  
|**Next**|**#1062 stranded ~1681 分類刷新（唯讀）** — 不 bulk repair；先確認 active risk／FP／canonical issue／code vs data 分離。Cross-SC ~50 並列調查但次之。|

Kickoff：`docs/internal/phase2/ORPHAN_SESSION_BASELINE.md` + 本輪 `ops-stranded-classify-refresh`（若本 PR 含）或後續 PR。

## 7. 已完成

Tracker／PII／bundle／repair workflow／exit gate／TD-059 monitor／SOP／tests。本輪無 production write。

## 8. Founder 決策？

**否。** 主任審核與 Ops 交付進行中；schema／1681 execute 仍需另開 GO。
