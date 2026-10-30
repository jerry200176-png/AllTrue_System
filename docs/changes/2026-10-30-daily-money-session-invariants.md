## 2026-10-30 — feat(ops): 每晚檢查四個金額／堂數不變式，漂移先被抓到（#4659）
<!-- release-notes: silent_ship=silent-2026-10-30-daily-money-session-invariants -->
<!-- silent-reason: 只新增夜間唯讀檢查與維運告警，沒有任何畫面或 API 行為改變 -->
- `bugs:verify-reproductions` 新增：共用方案多扣、同堂重複扣、未付款合約 Charge 與 Rate×堂數差 5 倍以上、Paid 旗標與發票不一致。基準線在 `config/reproduction_baselines.php`（未量測前只回報不失敗）；pi-health 排程失敗改推維運頻道。回滾＝還原這個 PR。
