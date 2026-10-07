## 2026-10-07 — fix(inapp): Phase-C closeout is atomic and never double-posts (#3742)
<!-- release-notes: silent_ship=silent-2026-10-07-phasec-writer-idempotent -->
<!-- silent-reason: 後台結案流程的可靠性修正；回報者收到的訊息內容不變，只是不會重複。 -->
- `bug-phase-c-allowlist.yml`：每筆結案在一個鎖列交易內完成（回覆＋轉態＋證據），同內容公開回覆已存在就不再發；中途失敗整筆 rollback。
