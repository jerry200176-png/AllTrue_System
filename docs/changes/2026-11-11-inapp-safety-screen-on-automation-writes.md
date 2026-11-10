## 2026-11-11 — fix(in-app): automation refuses to write to a safety-flagged report
<!-- release-notes: silent_ship=silent-2026-11-11-inapp-safety-screen-on-automation-writes -->
<!-- silent-reason: 只影響自動化回寫路徑（Phase A、follow-up、POP 上線後結案）；教職員畫面與人工在 App 內處理回報的流程不變 -->
- `BugSafetyScreen` was only applied at auto-intake and in the detail dump. The Phase A write-back, the follow-up comment and the POP `inapp-report-resolve-after-deploy` plan now run the same screen on the stored report and refuse (`safety_review_required`) when it is flagged. A person handles a flagged report in the app.
- No new code path on the server for the workflows: they call the existing `BugSafetyScreen::screen()`, so the check works before and after this deploys.
