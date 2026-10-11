## 2026-10-11 — perf: 非 production 禁止 lazy loading，防止 N+1 復發
<!-- release-notes: silent_ship=silent-2026-10-11-prevent-lazy-loading -->
<!-- silent-reason: 只在測試與開發環境擋 N+1，並補上漏掉的預先載入；畫面與結果不變。 -->
- 開啟 `Model::preventLazyLoading()`（production 除外）。全套測試掃出 5 處 lazy load（兼職薪資、堂次投影、錯誤回報詳情、堂次稽核），已改為預先載入。兼職薪資明細的科目名稱改讀堂次覆寫科目的 `Subject_Name`（原本只有課程層級科目有效）。Refs #3813 #3814 #3815 #3816
