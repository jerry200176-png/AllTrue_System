# AllTrue Audit Log Policy

> **狀態**：v1 design（2026-05-23）｜**Issue**：#492 ｜**Epic**：#469
> **下一步**：依本檔開 `feat/audit-log-v1` PRD 實作。本檔 = 設計與保留政策；尚未實作。

## 範圍（必須記錄的行為）

| 類別 | 範例 endpoint | 為什麼 |
|------|-------------|------|
| super_admin 高權操作 | `POST /admin/clear-data`、跨校資料修補、`User.type` 變更 | 防內部濫用 / 事後追查 |
| 主任審核行為 | `POST /director-accounts/{id}/approve`、`UserCampus.Approved` 變更 | 任用流程責任 |
| 跨校 override | director 帶 `branch_id` 不等於自身分校的查詢（>1 次/min） | 跨校資料存取監控 |
| 帳號生命週期 | `POST /auth/register`、密碼變更、token 撤銷 | 認證審計 |
| 資料匯出 | CSV / Excel 下載 endpoint | PII 外流追蹤 |
| 高風險財務變更 | `StudentClass.Charge/Pay/Paid` 直接 UPDATE、`PaymentReport` 撤銷 | 帳務責任 |

## 表 schema（建議）

```sql
CREATE TABLE audit_logs (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  occurred_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actor_user_id BIGINT UNSIGNED NULL,        -- NULL = system / cron / webhook
  actor_role VARCHAR(32) NULL,                -- super_admin / director / teacher / system
  actor_campus_id INT UNSIGNED NULL,
  action VARCHAR(64) NOT NULL,                -- e.g. "user.approved", "data.export.csv"
  target_type VARCHAR(64) NULL,               -- "User", "StudentClass" ...
  target_id BIGINT UNSIGNED NULL,
  target_campus_id INT UNSIGNED NULL,         -- 用於跨校 override 偵測
  metadata JSON NULL,                         -- 不含密碼、token、信用卡
  ip_address VARBINARY(16) NULL,              -- INET6 二進位
  user_agent VARCHAR(255) NULL,
  request_id VARCHAR(32) NULL,                -- 對應 Laravel log request_id（#485）
  INDEX (actor_user_id, occurred_at),
  INDEX (action, occurred_at),
  INDEX (target_type, target_id),
  INDEX (target_campus_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## 寫入路徑

- 新 service：`App\Services\AuditLogger::record(...)`
- middleware：`audit:<action>` 包覆敏感 route
- 統一 facade `AuditLog::record(action: 'user.approved', target: $user, metadata: [...])`
- ⛔ 禁止：透過 `DB::table('audit_logs')->insert(...)` 散落各處

## 保留政策

| 資料 | 保留期 | 處置 |
|------|--------|------|
| `audit_logs` row（一般） | **180 天** | 180 天後 archive 到壓縮 SQL 檔（`/home/admin/backups/audit_archive/YYYY-MM.sql.gz`），DB row 刪除 |
| 個資相關（target_type=`Student` / `Parent`） | 180 天線上 + 5 年離線 | 個資法保存：完成業務目的後 5 年內可查閱（仿主管機關建議） |
| `audit_logs` 含跨校 override | 365 天 | 規範審查需要 |

## PII / 法遵

- `metadata` 嚴禁含：密碼、token、信用卡、銀行帳號
- `metadata` 允許含：學生 id、課程 id、新舊值 diff（非 PII 範圍）
- 台灣個資法：本表本身為 **內部安全紀錄**，使用者可申請查閱本人相關紀錄（response 限該使用者 actor / target row）

## 查詢介面

| 角色 | 可查 | 限制 |
|------|------|------|
| `super_admin` | 全部 | 不可 export 整表（仅按 action / target 過濾查詢）|
| `director` | 自校 actor / target | 不可看 super_admin 操作 |
| `teacher` | 僅自己（authenticated GET `/me/audit-log`） | 只回顯示性訊息（隱去 IP） |

## 與其他 SOP 的關係

- `SRE_POLICY.md`：SLI-04（5xx 率）相關 audit row 進入每日 digest
- `OPERATIONS_RUNBOOK.md`：事故調查時 audit log 為第一引用來源
- `ASVS_L1_2026.md` 條目 4.3.1：本檔是其滿足策略

## 實作里程碑（不在本 PR 範疇）

1. v1：Schema + AuditLogger service + super_admin 高權 endpoint 覆蓋
2. v2：跨校 override 自動偵測 + alert（接 LINE）
3. v3：使用者查閱介面（GET `/me/audit-log`）

預估：4 個 PR / 2 週工程時間 / T3 SEC review。
