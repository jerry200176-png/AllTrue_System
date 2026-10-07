## 2026-10-07 — perf(chat): unread badge count in one query (#3587)
<!-- release-notes: silent_ship=silent-2026-10-07-chat-unread-single-query -->
<!-- silent-reason: 只改未讀數的查詢方式（N+1 → 1 次），數字與畫面不變。 -->
- `ChatService::totalUnread` 原本每個聊天室各查一次聊天室與一次未讀數（2N 次），改成單一彙總查詢；校區範圍、已讀標記、已退出聊天室的規則不變。
