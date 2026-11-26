## 2026-11-26 — 上線前的「測試已綠」證據只認主線自己的測試
<!-- release-notes: silent_ship=silent-2026-11-26-trusted-ci-evidence -->
<!-- silent-reason: CI and deploy hand-off evidence rules only; no product behaviour changes. -->

- 上線流程檢查「這個版本測試有過」時，只認主線自己觸發的測試（#5663）。
- 補救流程不再把「前一個版本的失敗測試留下的空紀錄」當成已上線（#5659）。
