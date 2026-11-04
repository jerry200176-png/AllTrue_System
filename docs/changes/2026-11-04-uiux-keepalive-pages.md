## 2026-11-04 — fix(ui): 主任儀表板／課程管理／學生／帳務中心／班級行事曆切換頁面後保留原狀態（#4659 step 1）
<!-- release-notes: staff_update=staff-2026-11-04-uiux-keepalive-pages -->
- The 5 pages are wrapped in `<KeepAlive>` in App.vue, so filters, search text, open tab and scroll survive a switch away and back. Navigation context (`initial-*` props) and branch switches are watched by each page, so they still apply to the cached page. Hidden pages drop their document listeners and scroll lock; the 帳務中心 student list refreshes quietly on return.
