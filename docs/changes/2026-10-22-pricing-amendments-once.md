## 2026-10-22 — 堂次單價調整每門課只讀一次
<!-- release-notes: silent_ship=silent-2026-10-22-pricing-amendments-once -->
<!-- silent-reason: 效能修正：月結與欠帳補單逐堂查單價時，同一門課的單價調整只查一次；金額與選擇規則不變。 -->

- `StudentClassPricingService::forDate()` 未預載單價調整時，改為每門課讀一次未作廢的調整、再逐日在記憶體內選出生效那筆（同日取較新的一筆），不再每堂各查一次（Refs #3756）。
- 生效規則、作廢排除與既有快取語意不變；新增測試鎖住查詢次數與逐日選擇結果。
