# Phase-C reply rule 2026-10-07 (batch B)

This PR now carries only the reply template rule. In-app 333 was removed after review.

**Rule:** every new Phase-C reply must offer both 「確認已修好」 and 「問題仍存在」.
`scripts/ci/bug-writeback-workflow.test.mjs` enforces it:
- It parses every numeric allowlist block, whatever the indentation or field order.
- It rejects duplicate IDs (PHP keeps only the last duplicate).
- The 130 pre-rule replies (already sent, not re-sent by decision) are exempt only while
  their reply text is byte-identical to the frozen hash. Any edit, for example a later
  re-dispatch, must comply with the rule.

**Deferred: in-app 333 (#3138).** Course 448's weekly template still carries the foreign
slot 4@19:00. The course is stopped, but resuming it (togglePause) and then purchaseBatch
would generate sessions from that template again. 333 stays open until one of these:
(a) a guarded single-row template correction for course 448, which is a T3 data repair
on the exact manifest; or (b) a product decision that stopped course 448 is never resumed.
