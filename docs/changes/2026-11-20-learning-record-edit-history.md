## 2026-11-20 — fix(learning-records): editing an assessment keeps the previous version (TD-074)
<!-- release-notes: silent_ship=silent-2026-11-20-learning-record-edit-history -->
<!-- silent-reason: 後台保護：修改老師寫的評量內容前先保存舊版；畫面與操作不變。 -->

- 評量記錄的老師正文被修改時，舊版會先保存成唯讀紀錄（跟刪除快照同一張表）。這是後台資料，不會出現「原內容已保存」提示，也不會影響作者認定。誤改的內容之後能找回。
