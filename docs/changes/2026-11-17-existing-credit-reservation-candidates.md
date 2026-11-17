## 2026-11-17 — feat(billing): identify explicit future credit-release candidates
<!-- release-notes: silent_ship=silent-2026-11-17-existing-credit-reservation-candidates -->
<!-- silent-reason: 尚未接上轉用寫入或操作介面，只準備交易內的未來堂次候選計算。 -->

- 沿用既有完整參照守衛及鎖定讀取，只提議尚未開始、沒有關聯紀錄且單位一致的已排堂次，保留明確的尾堂順序。
- 已開始、已標記、不同堂長或價格、有評量／具日期排課等歷史的堂次不會成為可釋出候選；候選計算本身不取消堂次或更改金額。
- 尚缺整批配置的預覽、明確確認、原子寫入、還原與介面；不代表正式轉用功能已完成。
