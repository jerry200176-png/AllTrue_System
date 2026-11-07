## 2026-11-07 — fix(billing): a running month's bill is not flagged against lessons still to come (in-app #518)
<!-- release-notes: staff_update=staff-2026-11-07-inapp-518-month-bill-warning -->
- 月結課程當月還有未上的預排堂時，帳單金額高於「已上堂數」試算不再顯示「帳單總額與堂次試算不同」；整月結束後才會比對。只影響課程列表／課程管理的顯示，帳務守門與重算規則不變。
- 真的不一致時，課程管理改成一句話加一個按鈕：「帳單金額和已上堂數算的不同，請到帳單更正。」
