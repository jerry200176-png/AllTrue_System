# Incident repair plan — 2026-08-28 翟君和 13:00 社會堂次

## 範圍與根因

- 目標：學生 #9、課程 #3112（社會）、堂次 #29212，2026-08-28 13:00–15:00。
- 主任確認該堂實際未上課，但 production 曾被反覆標記為 `attended`；因此留下 live 評量 #17922、點名 #11003 與一筆正向扣堂台帳。
- 根因不是只改畫面文字：狀態、評量、點名、扣堂與課程衍生堂數必須同一筆交易反向更新，否則會污染科目與評量統計。

## 修復命令與安全閘門

- 固定命令：`repair:unattended-session-29212`。
- 只接受內建的學生／課程／堂次／日期／時段組合，不接受任意 production ID。
- 先執行 dry-run，再由 `.github/workflows/ops-unattended-session-29212.yml` 要求 `PRODUCTION` 與 `I_APPROVE_UNATTENDED_29212`，並先建立資料庫備份。
- 交易內：作廢 live 評量與點名（原因 `由已上調整狀態`）、以 `SessionDeductionService` 反向扣堂、改為 `scheduled`、重算課程計數、寫 `schedule_audit_logs`。
- 重跑後若已是「未上且沒有 live 評量／點名／正向台帳」，只回報已對齊，不新增 reverse 或稽核資料。

## 驗收不變式

| 項目 | 修復後預期 |
|---|---|
| ClassSession #29212 | `scheduled`（畫面顯示未上） |
| LearningRecord #17922 | `VoidedAt` 非空、不可列入待填／科目統計 |
| StudentSignIn #11003 | `VoidedAt` 非空、不可列入出勤 |
| session ledger #29212 | deduct 與 reverse 淨值為 0 |
| StudentClass #3112 | 由權威來源重算，不直接覆寫手工堂數 |
| 稽核 | 至少一筆狀態前後快照，含操作人與原因 |

## 回滾

執行 workflow 會在 `/home/admin/backups/emergency/db_pre_unattended_29212_<timestamp>.sql.gz` 建立修復前備份，並在 `storage/app/repair-snapshots/` 留下快照。若驗收失敗，停止後續操作，由管理員依該時間戳備份在維護窗口還原，並以 snapshot 對照；不得直接手改 `UsedSessions` 或刪除稽核紀錄。

