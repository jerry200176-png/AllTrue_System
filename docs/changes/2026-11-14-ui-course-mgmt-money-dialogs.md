## 2026-11-14 — feat(ui): 課程查找更正堂數、轉成多科共用與確認視窗改用統一視窗版型 (issue 4989)
<!-- release-notes: silent_ship=silent-2026-11-14-ui-course-mgmt-money-dialogs -->
<!-- silent-reason: Slice 7b of the 課程查找 page-template migration announced with slice 1; 更正未收款堂數, 轉成多科共用, the 預排日期 question and the 暫停／刪除課程 confirms now use the shared dialog (focus trap, Esc, bottom sheet on a phone, 44px buttons, unsaved-changes prompt on the two forms), no new feature, field, number, wording or request change. -->
- 課程查找的「更正未收款堂數」「轉成多科共用」以及預排日期、暫停課程、刪除課程的確認視窗，改用全系統統一的視窗樣式：手機上貼在畫面下方、按鈕固定在底部，鍵盤 Tab 不會跑到視窗外，按 Esc 可關閉（兩個填寫視窗有修改時會先問你）並回到原本的按鈕。欄位、數字、提示文字、按鈕文字與送出內容都不變。
