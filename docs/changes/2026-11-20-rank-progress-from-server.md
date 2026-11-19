## 2026-11-20 — refactor(engagement): rank progress bar reads the server's rank table (TD-017)
<!-- release-notes: silent_ship=silent-2026-11-20-rank-progress-from-server -->
<!-- silent-reason: 進度條改用後端回傳的升階門檻計算，數字與現在完全相同；畫面不變。 -->

- 軍階進度條改用 `/me` 回傳的 `xp_at_current`、`next_rank_xp`，前端不再自己存一份軍階門檻表；之後調整軍階表只改後端一處。
