## 2026-10-07 — feat(billing): settled-course reconciliation label in plain wording (PRD v2 §0.2)
<!-- release-notes: silent_ship=silent-2026-10-07-backend-recon-label-plain -->
<!-- silent-reason: 同一批白話用字已由「繳費狀態改用白話」更新卡（#3716）公告，這裡只補後端標籤，不另發卡。 -->
- 帳務中心「已結案課程」的待確認標籤（後端 `reconciliation_label`）由「結案待對帳」改為「課已結束，等你確認收款」，與前端共用文字一致；狀態與判斷不變。
