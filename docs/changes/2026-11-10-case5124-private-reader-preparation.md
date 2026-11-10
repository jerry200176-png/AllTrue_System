## 2026-11-10 — Prepare an inactive fixed-case encrypted reader
<!-- release-notes: silent_ship=silent-2026-11-10-case5124-private-reader-preparation -->
<!-- silent-reason: 只準備內部加密唯讀通道；真實收件者信任與啟用尚待具體批准，教職員畫面無變更 -->

- R3/T3 preparation only. Existing case-dump/DUMP encryption and cleanup remain;
  the default is legacy. The fixed opt-in refuses access without approved trust.
- Native CMS and synthetic tests cover private staging, source/artifact/scope
  binding, expiry immediately before read publication and failure cleanup.
  Proposed public certificate/policy prepare the concrete decision package;
  they are not main-trusted or activated. No new database query, production write or reader
  registration. Concrete Founder GO is required before merge/activation.
- [Custody, limits and proposed trust](../../operations/closeout/case5124-reader.README.md).
  This read payload is not a consistent repair/rollback manifest.
