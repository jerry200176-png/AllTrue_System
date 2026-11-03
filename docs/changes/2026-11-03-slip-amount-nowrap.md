## 2026-11-03 — fix(billing): the slip's big amount stays on one line (in-app #466)
<!-- release-notes: silent_ship=silent-2026-11-03-slip-amount-nowrap -->
<!-- silent-reason: one CSS rule on the slip/receipt amount; amounts, wording and data are unchanged. -->
- `BillingDocument.vue` `.slip-amount` gets `white-space: nowrap`. In the PNG export the font could render wider than on the page, so 「NT$ 4,800」 wrapped onto two lines and spilled out of the orange amount box.
