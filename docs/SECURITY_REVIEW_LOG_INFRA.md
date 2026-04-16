# 資安審查：樹莓派儲存與 Log 寫入優化

> 對應 PRD: pi-storage-log-prd v1.1 (2026-04-16)
> 審查日期: 2026-04-16

---

## 1. 存取控制

| 項目 | 狀態 | 說明 |
|---|---|---|
| log 策略變更權限 | PASS | setup/rollback 腳本需 `sudo`（root），一般使用者無法執行 |
| systemd timer 權限 | PASS | timer/service 由 root 擁有，位於 `/etc/systemd/system/` |
| tmpfs 掛載權限 | PASS | 設定 `mode=0770,uid=www-data,gid=adm`，僅 www-data 與 adm 群組可存取 |
| 既有 `role:*` / `require_campus` | PASS | 本次變更不涉及 API 授權邏輯 |
| `laravel.log` 檔案權限 | WARN | 目前為 `0664 admin:admin`（world readable）；建議改為 `0640 www-data:adm` |
| `perf.log` 檔案權限 | WARN | 目前為 `0664 www-data:www-data`；建議改為 `0640 www-data:adm` |

### 建議動作

```bash
sudo chown www-data:adm /home/admin/backend/storage/logs/*.log
sudo chmod 640 /home/admin/backend/storage/logs/*.log
```

---

## 2. PII 與敏感資料

| 檢查項 | 結果 | 詳情 |
|---|---|---|
| 手機號碼明文 | FOUND (3 筆) | SQL error log 含 Teacher 手機號碼（INSERT 語句被完整記錄） |
| 學生/老師姓名 | FOUND (3 筆) | 同上，SQL error 含 `T_Name` 明文 |
| Email | 未發現 | |
| Password 明文 | 未發現 | 僅 stack trace 中的 class 名稱 |
| Bearer Token 明文 | 未發現 | |

### 風險評估

- **中風險**：Laravel 預設 `QueryException` 會將完整 SQL（含參數值）寫入 log。在 `production` 環境下，建議：
  1. 在 `app/Exceptions/Handler.php` 中對 `QueryException` 的 `getBindings()` 做 masking。
  2. 或將 `LOG_LEVEL` 改為 `warning`（目前為 `debug`），減少低重要度資訊輸出。
- 本次 tmpfs 導入**不會擴大此風險**（資料最終仍落盤到相同路徑），但高頻落盤可能增加曝露窗口。

### 建議動作

- 短期：收緊 log 檔案權限（見上方）。
- 中期：在 Exception Handler 中 mask 敏感 SQL 參數。

---

## 3. STRIDE 快評

| 威脅類型 | 評估 | 緩解措施 |
|---|---|---|
| **Spoofing** | 低 | 策略變更需 root / sudo，無法被一般使用者偽造 |
| **Tampering** | 低 | tmpfs 記憶體內容僅 root/www-data 可寫；落盤使用 `cat >> target` 追加模式 |
| **Repudiation** | 低 | 操作記錄透過 systemd journal（`journalctl -u alltrue-log-flush`）與 syslog（`logger -t alltrue-log`）保留 |
| **Information Disclosure** | 中 | SQL error log 含 PII（見上方），建議收緊權限與 mask 參數 |
| **Denial of Service** | 低 | tmpfs 有 128 MB 上限與 80% 自動降級；不會耗盡系統記憶體 |
| **Elevation of Privilege** | 低 | flush/monitor 腳本以 root 執行但僅做 file I/O，無 setuid、無網路 |

---

## 4. 稽核軌跡

| 操作 | 可追溯性 |
|---|---|
| tmpfs 掛載/卸載 | `journalctl`、`mount` history |
| flush timer 啟停 | `systemctl` + `journalctl -u alltrue-log-flush.timer` |
| 落盤成功/失敗 | `journalctl -u alltrue-log-flush.service`、syslog `alltrue-log` tag |
| 緊急降級 | syslog `alltrue-log` WARNING/CRITICAL |
| 回滾操作 | 回滾腳本 stdout + syslog |

---

## 5. 結論

| 判定 | 狀態 |
|---|---|
| 阻擋項 | **無**（本次變更不引入新的高風險漏洞） |
| 建議項 | 2 項（log 檔案權限收緊、SQL 參數 masking） |
| 可上線 | **是**，建議項可於後續迭代處理 |

---

## 簽核

- [ ] 資安確認無阻擋風險
- [ ] 日期：________
- [ ] 審查人：________
