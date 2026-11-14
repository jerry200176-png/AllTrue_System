## 2026-11-15 — feat(billing): 驗證同價同堂長跨科餘額預覽
<!-- release-notes: silent_ship=silent-2026-11-15-subject-credit-preview -->
<!-- silent-reason: Backend-only preparatory preview endpoint; no UI entry or transfer execution. Staff-facing transfer remains a separate delivery. -->
- 同學生、同校區、同單價與堂長的已付未用堂數，可由受限唯讀預覽驗證；不取消排課、不移動歷史、不抵目的科舊欠款。
- 本批尚無執行端點或操作按鈕，不能宣稱跨科轉用已提供。
- 同批修補既有 Symfony Filesystem 路徑邊界漏洞（7.4.21 → 7.4.23）；未停用安全稽核。
- Refs #5197；SourceRef: alltrue:bug_report:535。
