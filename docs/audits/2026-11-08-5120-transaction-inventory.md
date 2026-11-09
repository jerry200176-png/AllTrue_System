# #5120 transaction inventory — historical evidence index

SourceRef: `alltrue:bug_report:523`. This inventory describes the pre-fix source; it is not the current transaction contract.

- The complete 2026-11-08 inventory remains preserved at [immutable commit 73f46d98](https://github.com/jerry200176-png/AllTrue_System/blob/73f46d98d9783bcbd080142082af8b4d2f996464/docs/audits/2026-11-08-5120-transaction-inventory.md).
- Historical scan: 558 PHP files, 249 text-related candidates, 353 explicit transaction boundaries and 168 write candidates without a local boundary. These are search candidates, not 521 confirmed defects.
- Rebuild the current candidate list with [the existing PHP tokenizer scanner](5120-evidence/inventory.php); review the real caller and transaction before drawing conclusions.
- The focused failure, isolation limits and original SQL evidence are in [the rollback investigation](2026-11-08-5120-rollback-concurrency.md).
- Current repair: lock the course, read the current LearningRecord with a locking read, recheck ownership/campus/state, then apply the existing effects once. `LearningRecordReviewerFromLoginTest` checks the stale binding; `LearningRecordRollbackConcurrencyTest` exercises two authenticated HTTP processes and the actual InnoDB wait.
- Source review and historical test output do not establish current CI, deployment, production-path verification or reporter acceptance.

The long generated inventory is linked rather than copied into every review context; its original commit remains available for audit.
