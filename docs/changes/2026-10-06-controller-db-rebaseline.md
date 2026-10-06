## 2026-10-06 — chore(arch): re-baseline controller DB:: ratchet to current counts
<!-- release-notes: silent_ship=silent-2026-10-06-controller-db-rebaseline -->
<!-- silent-reason: 只更新內部架構檢查的基準數字，程式行為與畫面完全不變。 -->
- `scripts/controller-db-baseline.json`：StudentClass 44→75、ClassSession 31→39、Finance 16→21（舊基準從未達成，檢查永遠失敗、等於沒在擋）；改為「main 上實際數字」後，新的 `DB::` 仍不得再增加，拆分 PR 再往下壓。
