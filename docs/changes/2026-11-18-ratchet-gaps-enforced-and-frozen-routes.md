## 2026-11-18 — chore(arch): fixed nightly checks stay enforced; frozen route list only shrinks
<!-- release-notes: silent_ship=silent-2026-11-18-ratchet-gaps-enforced-and-frozen-routes -->
<!-- silent-reason: 只新增 CI 檢查，不變更產品畫面、資料或操作。 -->

- FIT-21：夜間檢查裡已驗證修好（`enforced => true`）的條件不能被改回不強制或刪掉；名單只能變長。補上 #4991 第 1 批留下的缺口（FIT-20 只管容許數）。
- 凍結的「未宣告復原方式的寫入路由」名單現在由 presubmit 對 base 比對：只能刪行，任何分支加行都會失敗（沿用既有的 `check-ratchet-baseline-touch.mjs`，不改 workflow）。
