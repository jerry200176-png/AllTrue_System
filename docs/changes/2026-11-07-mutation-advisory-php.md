## 2026-11-07 — chore(ci): advisory Infection mutation testing covers every R3 backend path, with one sticky comment
<!-- release-notes: silent_ship=silent-2026-11-07-mutation-advisory-php -->
<!-- silent-reason: 只改開發流程中的建議性變異測試檢查，教職員看到的畫面與功能完全不變。 -->
- `infection-billing.yml` 在 PR 改到 R3 後端路徑（與 `autonomy_gate` 同一份清單）或帳務金額檔時，只對改動行跑 Infection；存活的 mutant（檔案:行號＋mutator）整理成單一黏性 PR 留言。仍為建議性，不擋合併，不排程。
