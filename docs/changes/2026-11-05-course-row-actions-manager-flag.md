## 2026-11-05 — fix(ui): 課程列在課程管理開關開著時也有主按鈕與 ⋯（#4911 follow-up）
<!-- release-notes: staff_update=staff-2026-11-05-course-row-actions-manager-flag -->
- Production builds with `VITE_COURSE_MANAGER_V1=true`. #4911 put the row's primary action and ⋯ menu only in the flag-off branch, so production rows showed only 管理課程 and lost the payment entry (in-app #396; post-deploy acceptance run 41977699007 failed). Rows now always render the primary + ⋯; with the flag on, 管理課程 sits beside them and the ⋯ menu has no 詳情 item (no inline details row in that mode).
