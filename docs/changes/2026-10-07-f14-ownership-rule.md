## 2026-10-07 — fix(inapp): Phase-C auto-closes issues again; cross-references are not ownership (F24)
<!-- release-notes: silent_ship=silent-2026-10-07-f14-ownership-rule -->
<!-- silent-reason: 後台 GitHub issue 自動關閉規則修正；回報者看到的內容不變。 -->
- close-issue 與 reconcile 只把標題 `in-app #N` 或行首 `SourceRef: alltrue:bug_report:N` 當成擁有；「Related／Cross-SourceRef」等交叉引用不再阻擋自動關閉。
