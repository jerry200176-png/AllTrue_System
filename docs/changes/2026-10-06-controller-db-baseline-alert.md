## 2026-10-06 — chore(arch): controller DB:: ratchet baseline 收緊 AlertController 9→7
<!-- release-notes: silent_ship=silent-2026-10-06-controller-db-baseline -->
<!-- silent-reason: 只把內部架構檢查的上限調低，教職員看到的畫面與功能完全不變。 -->
- `scripts/controller-db-baseline.json` 以 `--write` 棘輪下修 AlertController（只降不升）；StudentClass／ClassSession／Finance 目前計數高於基線（75/39/20 vs 44/31/16），腳本不會、也不應該調高，維持原值。
