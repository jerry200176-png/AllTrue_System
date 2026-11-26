## 2026-11-27 — 老師跨分校設卡號：有一個分校失敗就全部不寫
<!-- release-notes: silent_ship=silent-2026-11-27-teacher-rfid-atomic -->
<!-- silent-reason: Edge case of one save (a refused card at a second campus); the normal save is unchanged. -->

- 一次儲存老師在多個分校的卡號時，如果其中一個分校的卡號被拒絕（重複或太長），其他分校的卡號不會再被先寫進去（#3758）。
