## 2026-11-18 — chore(arch): nightly known-bad allowances may only shrink
<!-- release-notes: silent_ship=silent-2026-11-18-reproduction-baselines-shrink-only -->
<!-- silent-reason: 只新增 CI 檢查與一行流程規則，不變更產品畫面、資料或操作。 -->

- 夜間資料檢查的「已知壞資料容許數」現在由架構檢查看守：數字調高或新增非零容許數會讓 CI 失敗，修好資料才調低。
- 交付流程開發階段補一行：正式資料修復要留下一個夜間條件。屬於 #4991 預防計畫的橫向規則。
