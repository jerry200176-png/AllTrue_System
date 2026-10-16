## 2026-10-15 — 請假尾堂撞到預建續約：先預覽、再整批順延續約堂次（後端，in-app #410／#4152）
<!-- release-notes: silent_ship=silent-2026-10-15-inapp-410-leave-successor-shift -->
<!-- silent-reason: 這次只新增後端 API，畫面尚未接上，教職員看不到變化；前端預覽畫面另開 PR，屆時再加教職員更新卡 -->
- 新增 `POST schedules/leave-successor-shift/preview` 與 `/apply`（主任／超級管理員）：舊合約請假的尾堂會排進同學生同科目已預建的續約時，預覽列出舊合約尾堂與每一堂續約的原時間→新時間。
- 只移動未上課、未扣堂、無核准評量的續約堂次；老師、學生、教室任一衝突或任一堂不符合就整批停止，不做部分變更。
- 套用時重新計算並比對預覽 token，一個 transaction 寫入；重複送出不再寫入；兩份合約都不寫扣堂 ledger。套用預設關閉（`LEAVE_SUCCESSOR_SHIFT_APPLY_ENABLED`），等撤銷請假會擋下已順延的情況後才開。
