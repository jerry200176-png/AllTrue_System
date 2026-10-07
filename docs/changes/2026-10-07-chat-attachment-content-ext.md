## 2026-10-07 — fix(security): chat attachments saved with content-sniffed extension
<!-- release-notes: silent_ship=silent-2026-10-07-chat-attachment-content-ext -->
<!-- silent-reason: 只改上傳檔名副檔名的來源，正常圖片／文件上傳與顯示不變。 -->
- `ChatService::uploadAttachment` 存檔副檔名改用內容判斷（`extension()`），不再用使用者送來的檔名副檔名；JPEG 改名 `.html` 不會再以網頁格式放在 `/storage` 下（stored XSS）。`mimes:` 驗證本來就限定內容判斷的副檔名在白名單內，所以原本能傳的檔案都照樣能傳。
