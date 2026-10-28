## 2026-10-28 — fix(schedule): 請假／取消最後一堂撞到續課時說清楚原因
<!-- release-notes: staff_update=staff-2026-10-28-leave-tail-conflict-message -->
- 請假或取消一堂課後，系統要把它順延到下一個時段；若學生那個時段已有其他課（常見是續課），現在會寫出要順延到哪一天幾點、為什麼沒完成，不再顯示看不懂的「請改選其他日期／時間」（in-app #433）。
