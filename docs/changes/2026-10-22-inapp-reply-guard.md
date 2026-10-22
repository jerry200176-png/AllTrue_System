## 2026-10-22 — feat(pop): in-app 公開回覆的個資與連結檢查（ADR-POP-015）
<!-- release-notes: silent_ship=silent-2026-10-22-inapp-reply-guard -->
<!-- silent-reason: library only; nothing calls it until the POP resolve operation (#4428) lands, and no screen changes -->

- 新增 `InappReportReplyGuard`：檢查 POP 要貼給回報者的公開回覆。沿用 `inapp-writeback-rows.py` 的個資規則（電話、身分證、Email、金鑰、長雜湊），並且更嚴：電話分隔號含各種連字號，連結：只允許該回報自己的 GitHub issue 網址原文，不接受加長的 issue 號碼、路徑、埠號、黏上的主機名、零寬字元或全形網址。
