# PCR-2026-07-09-957-D1-R2 — Execution Record

> **狀態**：**EXECUTED** — 2026-07-09 21:48（UTC+8）  
> **核准**：CEO GO（A1+B，2026-07-09 晚間，Claude Code session 內書面選項確認）  
> **PCR**：[`957-d1-pcr-r2.md`](957-d1-pcr-r2.md) · Runbook：[`957-d1-deploy-runbook.md`](957-d1-deploy-runbook.md)

## 執行摘要

| 項目 | 結果 |
|------|------|
| 執行時間戳 | `TS=20260709214818` |
| Pre-execute 備份 | `/home/admin/backups/emergency/957-d1-r2_pre_20260709214818.sql.gz`（ClassSession + StudentClass，303 KB gz） |
| Audit 基線 | `intra_course_duplicate_groups = 21`（snapshot `audit-pre-r2-20260709214818.json`） |
| Dry-run STOP 檢查 | `groups=21 == deletions=21 == audit intra=21`；WOULD 清單零 cancelled 列 → **通過** |
| **A1 執行** | 21 列刪除，snapshot `d1-intra-r2-20260709214818.json` |
| A1 驗證（S1） | post-audit `intra_course_duplicate_groups = 0` ✅ |
| **B（Batch-0）dry-run** | 4 actions（= PCR 預期） |
| **B 執行** | CS 18569 → cancelled、CS 18602 → cancelled、SC 2264 → Stop=1、CS 3215 → cancelled；snapshot `189-191-batch0-20260709214818.json` |
| B 驗證（S3/S4） | SQL 確認 3 列 cancelled + SC2264 Stop=1 ✅ |
| 保留列驗證（S5） | CS 15636/15633 = attended、CS 13302 = completed（未變）✅ |
| Health（S6） | `{"status":"ok"}` @ 21:48:21 ✅ |
| Downtime | 0 |

## S2 / S3b（unique index）

Migration 於 PR #1121（active-only index：`ActiveSlotFlag` stored generated column）合併後，
由下一次 deploy 自動執行；驗證 `SHOW INDEX ... uq_class_session_slot`。
cancelled placeholder（789 組）依設計保留，僅供分析（Type-B）。

## 使用者側收尾

- in-app **#189**（加課重複）→ resolved + 公開回覆（陳品承 6/13、6/20）
- in-app **#191**（跨約重複）→ resolved + 公開回覆（吳夏妍 5/14）
- in-app **#175**（評量無故未填）→ resolved + 公開回覆
- in-app **#173** → 進度更新（跨約家族屬下一批修復）
- GitHub **#1095 / #1097** closed with evidence

## Rollback 資產（保留 30 天）

1. `957-d1-r2_pre_20260709214818.sql.gz`（表級全量）
2. `d1-intra-r2-20260709214818.json`（A1 rows_before）
3. `189-191-batch0-20260709214818.json`（B rows_before）

## 執行技術備註

初版執行腳本因 `set -e` + 遠端 shell 管線回傳碼異常而在 STOP 檢查前靜默退出（零寫入）；
重寫為「輸出落檔 + python3 驗證」後一次通過。教訓：production 執行腳本的關鍵驗證
不要依賴 shell 管線退出碼，改用檔案 + 明確斷言。
