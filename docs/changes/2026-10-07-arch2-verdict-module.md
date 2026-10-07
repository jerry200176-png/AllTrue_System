## 2026-10-07 — refactor(billing): Contract Money Verdict module (ARCH2 PR1)
<!-- release-notes: silent_ship=silent-2026-10-07-arch2-verdict-module -->
<!-- silent-reason: 新增一個還沒有任何畫面使用的內部模組，數字與畫面都不變。 -->
- 新增 `ContractMoneyVerdict`：一份合約進、一個不可變結論出（應收、已收、狀態、目前帳期、逐堂涵蓋）。目前只包住既有的計算，尚未有任何呼叫端改用。
