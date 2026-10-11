## 2026-10-11 — test: 五個主任端點加查詢次數預算
<!-- release-notes: silent_ship=silent-2026-10-11-query-budget-tests -->
<!-- silent-reason: 只新增測試與測試輔助，不改任何執行中的程式。 -->
- 新增 `assertQueryCountAtMost()`，並為重複課程、出缺勤列表、堂次列表、帳務明細、學生列表設查詢預算，N+1 回歸會讓 CI 失敗。Refs #3814 #3815 #3816
