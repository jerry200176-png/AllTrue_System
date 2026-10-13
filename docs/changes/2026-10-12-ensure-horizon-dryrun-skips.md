## 2026-10-12 — fix(scheduling): 預排試算（dry-run）不再把執行時會略過的重疊堂算進「將建立」
<!-- release-notes: silent_ship=silent-2026-10-12-ensure-horizon-dryrun-skips -->
<!-- silent-reason: EnsureSessionHorizon 預設關閉、僅 dry-run／shadow 報表使用，沒有使用者可見畫面；Phase-0 報表的「將建立」數字會下修。 -->
- `EnsureSessionHorizonService` 的 dry-run 現在與執行路徑共用同一個衝突判斷（同起點 R20＋寫入防護的部分重疊），`would_create` 扣掉會被略過的堂，另列 `would_skip`；Shadow 報表的 ensure 合計同步改用淨值。Refs #3727
