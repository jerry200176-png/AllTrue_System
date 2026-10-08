## 2026-10-08 — perf: 兩處 Sentry N+1（重複課程清單、評量完整性修復）
<!-- release-notes: silent_ship=silent-2026-10-08-sentry-perf-sweep -->
<!-- silent-reason: 只減少資料庫查詢次數，畫面與結果不變。 -->
- `finance/duplicate-courses`：學生與科目名稱改為一次查完，不再每組各查兩次。評量完整性修復：科目名稱表每次執行只讀一次，不再每堂重讀。Refs #3815 #3814 #3816
