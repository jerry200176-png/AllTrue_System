## 2026-11-18 — test(ui): reconcile eight stale native visual baselines
<!-- release-notes: silent_ship=silent-2026-11-18-visual-baseline-reconciliation -->
<!-- silent-reason: 僅更新已逐張核讀的測試基準，不變更產品畫面、資料或操作。 -->

- 課程查找、課程設定、帳務中心及綁定衝突審查的八張舊圖，對齊已合併的字級、導覽、查課與收據入口變更；候選來自相同原始碼樹的原生 Playwright Docker 執行，原基準雜湊逐一核對。
- 不改測試、fixture、差異門檻或執行範圍；獨立圖片複審及原生重新渲染通過後才合併。合成畫面不代表真實帳務個案已修復或驗收。
