## 2026-10-11 — fix(billing): 登記收款被擋時，說明原因與下一步
<!-- release-notes: staff_update=staff-2026-10-11-payment-guard-says-why -->
- 登記收款或確認入帳被擋時，不再一律說「已標記為已繳費」。款項已收齊、課程標成已繳費但帳單還沒收到款、暫時查不到繳費狀態，各有一句白話說明和下一步。
- 擋或放行的判斷完全沒有改，錯誤代碼 `course_already_paid` 也沒變，只換說明文字。
