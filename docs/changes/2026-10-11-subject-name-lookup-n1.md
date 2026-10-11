## 2026-10-11 — perf: 課程科目名稱查詢每科目只查一次
<!-- release-notes: silent_ship=silent-2026-10-11-subject-name-lookup-n1 -->
<!-- silent-reason: 只減少資料庫查詢次數，畫面內容不變。 -->
- `StudentClass::displaySubjectName()` 在列表與排序比較器裡逐筆查 Subject 表，是 Sentry 一整族 N+1（#3814 #3815 #3816 #2491）的共同來源。改為每個科目 id 在同一程序內只查一次（60 秒快取），所有呼叫端一次受益。Refs #3814 #3815 #3816 #2491
