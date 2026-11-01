## 2026-11-01 — refactor(students): move the cross-campus identity dialog out of StudentsList.vue (#4659)
<!-- release-notes: silent_ship=silent-2026-11-01-students-list-split-1 -->
<!-- silent-reason: internal refactor; same screen, copy and API -->
- The 跨分校學生身份關聯 dialog is now `components/students/IdentityLinkModal.vue`. The page keeps the state and the requests. No behaviour change.
