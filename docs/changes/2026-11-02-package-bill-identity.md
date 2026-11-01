## 2026-11-02 — fix(billing): 方案帳單記住所屬方案，改費率後同方案其他成員收款仍付同一張帳單（#4766）
<!-- release-notes: silent_ship=silent-2026-11-02-package-bill-identity -->
<!-- silent-reason: backend billing-match fix, no screen or API shape change; staff see no new UI -->
- 補登方案收款開出的總價帳單，現在在既有 `billing_snapshot` 記下 `source=package_obligation` 與方案 ID；同方案其他成員收款時依此認出該張帳單，不再比對「現在的 費率×總堂數」，所以中途改費率不會再多開第二張帳單。
- 沒有此標記的舊帳單仍照原本的總價比對。不需 migration。
