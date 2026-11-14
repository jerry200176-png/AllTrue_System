## 2026-11-14 — feat(ui): 學生管理學生列改用統一表格版型 (issue 4989)
<!-- release-notes: silent_ship=silent-2026-11-14-ui-students-rows -->
<!-- silent-reason: Second slice of the 學生管理 page-template migration; the student rows' layout, colours and menu only, no new feature, data or permission change. -->
- 學生管理的學生列改用全系統統一的表格樣式，字級設定（標準、稍大、大）現在會一起放大：姓名一律單行，不再有彩色頭像圓圈；暫停中用黃色標籤，已畢業、已轉校用灰色標籤；補習科目堂數不多時仍是紅色並會被讀出「即將用完」；RFID 與 LINE 改為一般文字，沒綁定顯示「未綁定」。每列只留一個「課程/購課」按鈕，編輯、學習檔案與刪除學生收進「⋯」選單，刪除學生放在最後並標為危險。勾選框會唸出「選取＋學生姓名」，全選框在只選一部分時顯示半選狀態。手機上每列改成姓名、年級・學校、科目堂數與狀態，點整列展開課程，不再需要左右捲動。展開後的課程安排、篩選、勾選與刪除的行為都不變。
