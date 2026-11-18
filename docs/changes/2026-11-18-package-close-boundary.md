## 2026-11-18 — fix(accounting): reject member-only close of a shared package
<!-- release-notes: staff_update=staff-2026-11-18-package-close-boundary -->

- 共用方案不再提供會只結束單一成員的結案動作；帳務卡保留方案須整包核對的說明、帳款與既有查找入口。
- 伺服器在原有校區授權與列鎖後，拒絕明示及自動推定的方案成員結案，也不接受以放棄餘額繞過。一般單科結案、普通暫停／恢復的既有規則保留。
- 依據 RFC_SHARED_PACKAGE_COLLECTION §3a／驗收40 的既有保守邊界，不新增整包取消、額度沒收或退款規則，無 migration 或正式資料修復。
- #5494／in-app573、574：合成資料已重現結案成功後同方案換成另一成員重新出現；本次是防止再次誤操作，原回報兩筆實際資料與單科原因尚待連結核對，不宣稱已結案。
