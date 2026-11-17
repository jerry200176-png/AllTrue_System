## 2026-11-17 — test(calendar): cover source565 through real scheduling endpoints
<!-- release-notes: silent_ship=silent-2026-11-17-staggered-slot-api-regressions -->
<!-- silent-reason: 為已合併修正補上實際端點的回歸防護，不變更正式功能或再發布教職員更新卡。 -->

- 補驗具體排課及實際原子調課端點：前後接續時段可加入第三位學生，真正同時第四位仍會拒絕；原有堂次、收款及扣堂紀錄保留。
- 沿用 #5440 的單一正式實作；重複的 #5443 已關閉未合併。本次只增加測試，不代表已部署或原回報者已驗收。Refs #5436；SourceRef: alltrue:bug_report:565。
