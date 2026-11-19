## 2026-11-19 — fix(scheduling): enrollment capacity check runs under the teacher lock (TD-094)
<!-- release-notes: silent_ship=silent-2026-11-19-enrollment-capacity-lock -->
<!-- silent-reason: 只把既有的老師時段檢查移到寫入交易裡並加鎖；規則、訊息與畫面都不變。 -->

- 新增課程時，「老師這個時段是否已滿」的檢查改在寫入的同一個交易裡做，並先鎖住該老師（與試聽暫留共用同一把鎖）。同一秒有兩個人排同一位老師時，第二筆會被正確拒絕，不再兩筆都成功。
- 拒絕時整筆撤回，包括同時新建的學生資料。
