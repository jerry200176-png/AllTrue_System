## 2026-10-13 — fix(binding): 解除 LINE 綁定時一併收回監護人權限
<!-- release-notes: staff_update=staff-2026-10-13-unbind-revokes-guardian-access -->
- 解除 LINE 綁定後，該 LINE 帳號會同時失去查看學生資料的權限並被登出（家長 LINE 綁定頁與學生資料頁的解除綁定都適用）。
- 同一位學生的其他家長、以及這個 LINE 帳號綁定的其他學生，不受影響（Closes #3933）。
