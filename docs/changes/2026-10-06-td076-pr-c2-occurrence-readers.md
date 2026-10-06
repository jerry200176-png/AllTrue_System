## 2026-10-06 — feat(scheduling): TD-076 Track B PR-C2 其餘讀取端改讀同一個「這堂誰教」（旗標關閉）
<!-- release-notes: silent_ship=silent-2026-10-06-td076-pr-c2-readers -->
<!-- silent-reason: 全部在 schedule-occurrence-v2 旗標後面，正式站旗標關閉，教職員看到的畫面與功能完全不變。 -->
- 容量釋放、排課衝突檢查、老師行事曆、課程範圍、全域搜尋、學習紀錄統計在旗標開啟時讀同一個答案。
- #3590 item 1：旗標回滾後又改期，undo／還原仍能找到寫入端建立的列（以身分 + 變更紀錄作穩定關聯）。item 3：找不到有效列時的舊版清理 fallback 補上測試。
