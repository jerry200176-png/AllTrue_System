## 2026-10-31 — style(ui): 老師、通知、出缺勤、學生、登入與代課提示移除單邊粗色條（UI-OSS-2 batch 2）
<!-- release-notes: silent_ship=silent-2026-10-31-calmer-accents-batch2 -->
<!-- silent-reason: Visual-only CSS: thick one-sided accent borders replaced by tinted full borders or removed where a badge/label already carries the status; no behavior, API or text change. -->
- 學習評量、老師首頁、通知中心、出缺勤、學生列表、登入頁與代課 Toast 的單邊粗色條改為淡色外框／底色或移除（狀態文字與徽章不變）；移除老師首頁卡片頂端裝飾條。只有 CSS 變更；回滾＝還原這個 PR。
