## 2026-10-22 — Focus stays on the course list after closing a course from 管理課程
<!-- release-notes: silent_ship=silent-2026-10-22-course-manager-close-focus -->
<!-- silent-reason: keyboard focus fix; no visible change for mouse users -->

Closing a course from the 管理課程 drawer under 「進行中」 reloads the list, which removes that course's row and with it the 管理課程 button that opened the drawer. The drawer returned focus to that button, so keyboard and screen-reader users landed on the page body. After the render, the drawer now checks where focus went; if it is on the body or a removed element, it calls the page's fallback, which focuses the student's group toggle, or the course list card when the student has no other listed course. Normal closes still return focus to the opener. Tests in `CourseManager.test.js`. Rollback is a normal revert. Refs #4178.
