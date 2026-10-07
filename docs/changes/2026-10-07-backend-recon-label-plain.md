## 2026-10-07 — feat(billing): settled-course reconciliation labels and waive message in plain wording (PRD v2 §0.2)
<!-- release-notes: silent_ship=silent-2026-10-07-backend-recon-label-plain -->
<!-- silent-reason: 同一批白話用字由「繳費狀態改用白話」更新卡（#3716）公告；這裡只補後端標籤與不收訊息，不另發卡。 -->
- 帳務中心「已結案課程」的待確認標籤（後端 `reconciliation_label`）：「結案待對帳」改為「課已結束，等你確認收款」，「暫停中 · 待對帳」改為「暫停中・等你確認收款」；不收的錯誤訊息改用「不收了」說法。狀態與判斷不變。
