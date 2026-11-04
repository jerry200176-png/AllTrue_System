## 2026-11-04 — feat(ui): 登記收款／補登收款改在右側面板打開（#4659 step 3）
<!-- release-notes: staff_update=staff-2026-11-04-payment-side-sheet -->
- `AtDialog` gets `placement="right"` (full-height sheet, `min(420px,100%)`, slide-in, full-screen on phones; same focus trap, Esc, scroll lock and teleport). `PaymentEntryModal` takes `placement` and renders in that sheet.
- 課程查找 row 登記收款/補登收款 pass `sheet-entry` to `AccountingLedgerModal`: the ledger still loads and runs every guard, but only the sheet shows. If the form does not open (blocked course or load error) the ledger shows with the reason, as before. Saving closes the sheet and quietly reloads the list. No endpoint, validation or backend change.
