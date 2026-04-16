# QA 驗收矩陣：樹莓派儲存與 Log 寫入優化

> 對應 PRD: pi-storage-log-prd v1.1 (2026-04-16)
> 基線快照: `docs/baselines/baseline_20260416_143105.md`

## 自動化測試

執行方式：`bash scripts/infra/test-log-infra.sh`
涵蓋 FR-000 ~ FR-008 共 17+ 項自動檢查。

---

## 手動驗收矩陣

### FR-000: 基線快照

| 案例 | 類型 | 步驟 | 預期結果 | Pass/Fail |
|---|---|---|---|---|
| 基線檔案存在 | Happy | `ls docs/baselines/baseline_*.md` | 至少一份報告 | |
| 基線含所有指標 | Happy | 讀取報告，確認有 lsblk、log size、API P95 | 各區段非空 | |
| 基線指令權限不足 | Error | 以非 root 執行且 logs 不可讀 | 腳本提示錯誤，**禁止繼續後續 FR** | |

### FR-001: Log Rotation

| 案例 | 類型 | 步驟 | 預期結果 | Pass/Fail |
|---|---|---|---|---|
| daily 輪轉生效 | Happy | 等待隔日，確認 `laravel-YYYY-MM-DD.log` 產生 | 新日期檔出現，舊檔案存在 | |
| 14 天後自動刪除 | Edge | 確認 15 天前的舊檔被清除 | 僅保留最近 14 天 | |
| 輪轉中不遺失 log | Edge | 在午夜輪轉窗口期間觸發大量 API 請求 | 所有請求的 log 行不遺失 | |
| 排障流程不變 | Regression | `tail -30 backend/storage/logs/laravel-$(date +%Y-%m-%d).log` | 可正常讀取當日 log | |

### FR-002/003: 儲存介質盤點

| 案例 | 類型 | 步驟 | 預期結果 | Pass/Fail |
|---|---|---|---|---|
| 盤點腳本可執行 | Happy | `bash scripts/infra/storage-inventory.sh` | 輸出含 lsblk、root device、assessment | |
| 節點為 SSD/NVMe | Happy | 確認 Assessment 輸出 PASS | 非 SD 卡 | |
| SD 卡偵測 | Edge | 在 SD 卡開機的裝置執行 | 輸出 FAIL 並建議遷移 | |

### FR-004/005: Log 分級 + Tmpfs

| 案例 | 類型 | 步驟 | 預期結果 | Pass/Fail |
|---|---|---|---|---|
| tmpfs 掛載成功 | Happy | 執行 setup-log-tmpfs.sh 後 `df -h /var/log/alltrue-tmpfs` | 128 MB tmpfs 可見 | |
| 落盤排程正常 | Happy | 等待 5 分鐘，確認 systemd timer 觸發 | `journalctl -u alltrue-log-flush` 有成功紀錄 | |
| tmpfs > 80% 降級 | Edge | 人工寫入大檔案撐到 > 80% | monitor 觸發 emergency flush | |
| 落盤連續失敗 3 次 | Error | 模擬 PERSIST_DIR 不可寫 | timer 停止並寫入 syslog CRITICAL | |
| 稽核 log 不走 tmpfs | Happy | 確認 perf.log 仍直接寫入 storage/logs | perf 日誌路徑不變 | |

### FR-006: 監控與告警

| 案例 | 類型 | 步驟 | 預期結果 | Pass/Fail |
|---|---|---|---|---|
| Health 端點含 log_pipeline | Happy | `curl /api/v1/health` | JSON 含 `log_pipeline.tmpfs_active` | |
| tmpfs_healthy 正確 | Happy | tmpfs < 80% 時 | `tmpfs_healthy: true` | |
| 告警系統不可用時仍降級 | Edge | 模擬 logger 失敗 | 降級動作仍自動執行 | |

### FR-007: 一鍵回滾

| 案例 | 類型 | 步驟 | 預期結果 | Pass/Fail |
|---|---|---|---|---|
| 回滾完成 | Happy | `sudo bash scripts/infra/rollback-log-tmpfs.sh` | tmpfs 卸載、timer 停止、fstab 清理 | |
| 回滾冪等 | Edge | 連續執行兩次 rollback | 第二次不報錯 | |
| 回滾後 API 正常 | Regression | `curl /api/v1/health` | 200 OK | |
| 回滾後 P95 不退化 | Regression | 對比基線 P95 | 不高於基線 | |
| 回滾耗時 < 5 分鐘 | NFR | 計時整個回滾流程 | < 300 秒 | |

### FR-008: 文件更新

| 案例 | 類型 | 步驟 | 預期結果 | Pass/Fail |
|---|---|---|---|---|
| OPERATIONS_RUNBOOK 已更新 | Happy | 搜尋 tmpfs 或 log rotation 相關段落 | 有新增說明 | |
| deploy-raspberry-pi 已更新 | Happy | 搜尋 tmpfs setup 步驟 | 有新增段落 | |
| CHANGELOG 已更新 | Happy | 搜尋本次變更日期 | 有對應條目 | |

---

## KPI 驗收（對照基線）

| KPI | 基線值 | 目標 | 實際值 | Pass/Fail |
|---|---|---|---|---|
| laravel.log 每日寫入 | ~433 KB/day | 下降 ≥ 40% | | |
| API P95 (health) | ~14 ms | 不高於基線 | | |
| API P95 (branches) | ~14 ms | 不高於基線 | | |
| tmpfs 使用率 | N/A | < 50% (< 64 MB) | | |
| 儲存故障事件 | 0 | 3 個月內仍為 0 | | |

---

## 簽核

- [ ] QA 執行完成，阻擋項為 0
- [ ] 日期：________
- [ ] 執行人：________
