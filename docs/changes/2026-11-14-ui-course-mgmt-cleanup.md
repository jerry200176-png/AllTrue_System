## 2026-11-14 — feat(ui): 課程查找清掉舊樣式、暫停視窗輸入法不誤關 (issue 4989)
<!-- release-notes: silent_ship=silent-2026-11-14-ui-course-mgmt-cleanup -->
<!-- silent-reason: Slice 8 (last) of the 課程查找 page-template migration announced with slice 1; removes scoped CSS no element uses any more and ignores the Esc that ends 注音／拼音 input in the pause dialog's reason box; no new feature, field, number, wording or request change. -->
- 課程查找收尾：刪掉頁面裡已經沒有任何畫面在用的舊樣式，頁面看起來不變。超級管理員恢復課程時，在原因欄用注音／拼音輸入，按 Esc 結束選字不會再把視窗關掉。資料、按鈕、文字與送出內容都不變。
