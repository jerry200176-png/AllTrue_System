## 2026-11-19 — test(notifications): lock that the staff LINE observer is live (TD-065)
<!-- release-notes: silent_ship=silent-2026-11-19-staff-line-observer-live -->
<!-- silent-reason: 只修正一段誤導的程式註解並新增測試；推播行為、訊息與畫面都不變。 -->

- 新增測試確認「建立高嚴重度通知」就會推 LINE 給有綁定的老師／主任；修正 `AppServiceProvider` 裡說 observer 不會生效的錯誤註解。TD-065 因前提不成立結案，TD-075 結案，TD-073 範圍縮小。
