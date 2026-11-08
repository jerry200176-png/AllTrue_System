# #5120 LearningRecord / StudentClass 交易邊界靜態清單

來源：本機 `9e81a7356f3b79ebe36dfbd286fa70c120aa8a66`；backend runtime 與受審 `1ad294d3` 相同。

掃描 backend/app 全部 558 個 PHP 檔；249 檔文字引用 LearningRecord／StudentClass。以 PHP tokenizer 列出其中 353 個含顯式 transaction／begin／commit／rollback／row lock 的具名方法，以及 168 個含 write call 但無本地顯式邊界的方法候選。

這是完整 lexical 候選清單，不是每一條 runtime call graph 的鎖安全證明。鎖欄按 source 順序列出，條件分支、loop、helper／observer 與隱式 UPDATE 鎖須結合主報告判讀；「—」不代表執行時沒有鎖。某檔引用模型不表示其中每一個方法都寫該模型，因此清單保留 superset，沒有把它們全部宣告為漏洞。

重建：將 repo 唯讀 mount 至 `/src`、本資料夾 mount 至 `/evidence`，以 PHP CLI 執行 `php /evidence/inventory.php`；工具不載入 Laravel、不連 DB。

## 顯式交易／鎖方法

| Source method | Transaction / manual boundary lines | Explicit row-lock lines | Service calls in source |
|---|---|---|---|
| `backend/app/Console/Commands/BackfillLegacyPayments.php:21` `handle` | 87 | — | — |
| `backend/app/Console/Commands/BackfillPackageBinding.php:19` `handle` | 66 | — | — |
| `backend/app/Console/Commands/BackfillScheduleOccurrenceIdentityCommand.php:25` `handle` | 44 | — | — |
| `backend/app/Console/Commands/CloseOrphanStudentSignIns.php:31` `handle` | 75 | — | StudentPresenceBackfillService::backfill |
| `backend/app/Console/Commands/LeaveMakeupEvidenceCloseout.php:106` `probeLeave` | 111, 173 | — | CourseLeaveCascadeService::applyLeaveCascade, CourseLeaveCascadeService::undoLeaveCascade |
| `backend/app/Console/Commands/LeaveMakeupEvidenceCloseout.php:188` `probeMakeup` | 193, 257 | — | SessionDeductionService::deductOnAttendance, SessionDeductionService::reverseForSession, SessionDeductionService::recomputeCounters |
| `backend/app/Console/Commands/RepairChargeDisplay1734.php:32` `handle` | 35, 69 | 70, 74, 75 | — |
| `backend/app/Console/Commands/RepairChargeDisplay1734.php:131` `rollback` | 131 | — | — |
| `backend/app/Console/Commands/RepairConfirmedAttendanceAssessment.php:36` `handle` | 59 | 61, 86 | SessionDeductionService::deductOnAttendance, SessionDeductionService::recomputeCounters |
| `backend/app/Console/Commands/RepairDuplicateSessionSlots.php:26` `handle` | 62 | — | — |
| `backend/app/Console/Commands/RepairLeaveCascadeSlotTimes.php:38` `handle` | 149 | 151 | CourseLeaveCascadeService::syncLearningRecordSessionDate |
| `backend/app/Console/Commands/RepairLeaveVacatedWeeks.php:295` `applyFutureSafeRepair` | 305 | 307, 315, 337 | CourseLeaveCascadeService::alignSessionTimesToContractWeekday, CourseLeaveCascadeService::appendNote, CourseLeaveCascadeService::syncLearningRecordSessionDate, CourseLeaveCascadeService::isSafeToRemoveAutoAppend |
| `backend/app/Console/Commands/RepairRenewalOverlap234.php:36` `handle` | 38, 57 | 58, 59 | SessionDeductionService::reverseForSession, SessionDeductionService::recomputeCounters |
| `backend/app/Console/Commands/RepairRenewalOverlap234.php:118` `rollback` | 118, 128 | — | SessionDeductionService::deductForSession, SessionDeductionService::recomputeCounters |
| `backend/app/Console/Commands/RepairSupersedeRenewalSession.php:39` `handle` | 52, 83 | — | — |
| `backend/app/Console/Commands/RepairSupersedeRenewalSession.php:203` `apply` | — | 208 | — |
| `backend/app/Console/Commands/RepairSupersedeRenewalSession.php:252` `rollback` | 252, 278 | — | — |
| `backend/app/Console/Commands/RepairTransferSessionEntitlement.php:29` `handle` | 31 | — | — |
| `backend/app/Console/Commands/RepairTransferSessionEntitlement.php:102` `rollback` | 102, 120 | — | — |
| `backend/app/Console/Commands/RepairTransferredSessionLedger.php:33` `handle` | 67 | 68, 69 | SessionDeductionService::recomputeCounters, SessionDeductionService::batchExpectedUsedSessionDiagnostics |
| `backend/app/Console/Commands/RepairTyphoonAttendance1903.php:40` `handle` | 43 | — | — |
| `backend/app/Console/Commands/RepairTyphoonAttendance1903.php:75` `applyOne` | 77 | 78 | SessionDeductionService::reverseForSession, SessionDeductionService::recomputeCounters |
| `backend/app/Console/Commands/RepairTyphoonAttendance1903.php:144` `rollback` | 144, 163 | — | SessionDeductionService::deductForSession, SessionDeductionService::recomputeCounters |
| `backend/app/Console/Commands/RepairYang1445ContractMove.php:50` `handle` | 62, 100 | — | — |
| `backend/app/Console/Commands/RepairYang1445ContractMove.php:117` `plan` | — | 123 | — |
| `backend/app/Console/Commands/RepairYang1445ContractMove.php:368` `rollback` | 368, 391 | — | — |
| `backend/app/Console/Commands/SyncPackageSessionCounts.php:31` `handle` | 69 | — | — |
| `backend/app/Http/Controllers/AccountingController.php:321` `waiveCourse` | 329 | 330, 351 | — |
| `backend/app/Http/Controllers/AdminDuplicateSessionController.php:141` `patchP2Review` | 191 | 210 | SessionDeductionService::reverseForSession, SessionDeductionService::recomputeCounters |
| `backend/app/Http/Controllers/AdmissionInquiryController.php:134` `claim` | 140 | 142 | — |
| `backend/app/Http/Controllers/AdmissionInquiryController.php:184` `scheduleTrial` | 189 | 198 | — |
| `backend/app/Http/Controllers/AssessmentController.php:73` `store` | 86 | — | — |
| `backend/app/Http/Controllers/AssessmentController.php:110` `update` | 136 | — | — |
| `backend/app/Http/Controllers/AssessmentController.php:148` `publish` | 160 | — | — |
| `backend/app/Http/Controllers/AssessmentController.php:170` `close` | 182 | — | — |
| `backend/app/Http/Controllers/AssessmentController.php:222` `storeRemediationAction` | 240 | — | — |
| `backend/app/Http/Controllers/AssessmentController.php:262` `updateRemediationAction` | 286 | — | — |
| `backend/app/Http/Controllers/AssessmentController.php:450` `storeResult` | 495 | — | — |
| `backend/app/Http/Controllers/AssessmentController.php:517` `updateResult` | 537 | — | — |
| `backend/app/Http/Controllers/AssessmentController.php:596` `transitionResult` | 609 | — | — |
| `backend/app/Http/Controllers/AttendanceController.php:410` `store` | 664 | 430 | AttendanceEffectsService::assertCanRecordAttendance, CourseLeaveCascadeService::applyLeaveCascade, CourseLeaveCascadeService::leaveOutcomeForCourse, SessionDeductionService::deductOnAttendance |
| `backend/app/Http/Controllers/AttendanceController.php:736` `swipe` | 782 | 786, 792, 808 | AttendanceEffectsService::assertCanRecordAttendance, SessionDeductionService::deductOnAttendance |
| `backend/app/Http/Controllers/AttendanceController.php:894` `activeAttendanceExistsForSessionSlot` | — | 901, 910, 920 | — |
| `backend/app/Http/Controllers/AttendanceController.php:1003` `destroy` | 1031 | — | SessionDeductionService::reverseForSession, SessionDeductionService::recomputeCounters |
| `backend/app/Http/Controllers/AttendanceController.php:1185` `convertToAttended` | 1228 | — | SessionDeductionService::deductForSession, SessionDeductionService::recomputeCounters |
| `backend/app/Http/Controllers/BackfillController.php:23` `registerSubjectUnits` | 114 | — | — |
| `backend/app/Http/Controllers/BillingController.php:91` `store` | 128 | 131, 138, 151 | — |
| `backend/app/Http/Controllers/BillingController.php:205` `recordPayment` | 221 | 225 | — |
| `backend/app/Http/Controllers/BillingController.php:300` `lockedInvoiceProjection` | — | 302 | — |
| `backend/app/Http/Controllers/BillingController.php:321` `lockInvoiceCourses` | — | 328 | — |
| `backend/app/Http/Controllers/BillingController.php:335` `assertInvoiceOwnersUnchanged` | — | 338 | — |
| `backend/app/Http/Controllers/BillingController.php:348` `voidInvoice` | 360 | 363 | — |
| `backend/app/Http/Controllers/BillingController.php:423` `exceptionVoidInvoice` | 435 | 438, 449, 457 | — |
| `backend/app/Http/Controllers/ClassSessionController.php:1122` `update` | 1169, 1226 | 1171, 1227 | SessionDeductionService::reverseForSession, SessionDeductionService::recomputeCounters, SessionDeductionService::syncCounters |
| `backend/app/Http/Controllers/ClassSessionController.php:1371` `restore` | 1391 | 1392 | SessionDeductionService::deductOnAttendance, SessionDeductionService::recomputeCounters |
| `backend/app/Http/Controllers/ClassSessionController.php:1488` `restoreCancelledAttendanceArtifacts` | — | 1492, 1501 | — |
| `backend/app/Http/Controllers/ClassSessionController.php:2322` `substitute` | 2666 | — | OccurrenceAssignmentService::enabledFor, OccurrenceAssignmentService::onLeave, OccurrenceAssignmentService::onMakeup |
| `backend/app/Http/Controllers/ClassSessionController.php:2722` `runSubstituteTransaction` | 2722, 2746 | — | OccurrenceAssignmentService::enabledFor, OccurrenceAssignmentService::onMakeup |
| `backend/app/Http/Controllers/ClassSessionController.php:3181` `restoreOriginalTeacherFromSubstitute` | 3203 | — | OccurrenceAssignmentService::handles |
| `backend/app/Http/Controllers/CoursePackageController.php:243` `createMultiSubject` | 342 | — | EnrollmentService::teacherAssignmentErrors, PackageDeductionService::deductUnderSlotRule |
| `backend/app/Http/Controllers/CoursePackageController.php:680` `convertToPackage` | 718 | 719 | SessionDeductionService::batchExpectedUsedSessionDiagnostics, PackageDeductionService::deductForSession |
| `backend/app/Http/Controllers/CoursePackageController.php:841` `bindCourses` | 935 | 936, 937 | — |
| `backend/app/Http/Controllers/CoursePackageController.php:1165` `update` | 1248 | — | PackageDeductionService::fullRecompute |
| `backend/app/Http/Controllers/DirectorAccountController.php:123` `approve` | 137 | — | — |
| `backend/app/Http/Controllers/DirectorAccountController.php:152` `reject` | 166 | — | — |
| `backend/app/Http/Controllers/DirectorAccountController.php:222` `resetPassword` | 237 | — | — |
| `backend/app/Http/Controllers/DirectorAccountController.php:287` `destroy` | 303 | — | — |
| `backend/app/Http/Controllers/DirectorAccountController.php:354` `updateCampuses` | 376 | — | — |
| `backend/app/Http/Controllers/ExamRoundController.php:40` `store` | 70 | — | — |
| `backend/app/Http/Controllers/ExamRoundController.php:101` `update` | 119 | 120 | — |
| `backend/app/Http/Controllers/ExamRoundController.php:142` `destroy` | 149 | 150 | — |
| `backend/app/Http/Controllers/ExamRoundController.php:171` `restore` | 176 | 177 | — |
| `backend/app/Http/Controllers/ExamRoundController.php:290` `upsertScores` | 362 | 363, 384 | — |
| `backend/app/Http/Controllers/ExamRoundController.php:553` `lifecycle` | 579 | 580 | — |
| `backend/app/Http/Controllers/ExceptionWorkflowController.php:104` `confirmCandidate` | 138 | 194 | CourseLeaveCascadeService::voidLiveArtifactsForLeave |
| `backend/app/Http/Controllers/FeeLedgerController.php:56` `storeCharges` | 73 | 76 | — |
| `backend/app/Http/Controllers/FeeLedgerController.php:94` `pay` | 101 | — | — |
| `backend/app/Http/Controllers/FeeLedgerController.php:113` `voidCharge` | 117 | — | — |
| `backend/app/Http/Controllers/FeeLedgerController.php:146` `voidPayout` | 150 | 151 | — |
| `backend/app/Http/Controllers/FeeLedgerController.php:173` `lockedCharge` | — | 175 | — |
| `backend/app/Http/Controllers/FinanceController.php:1430` `parttimePayrollLock` | 1465 | 1467 | — |
| `backend/app/Http/Controllers/LearningRecordController.php:1053` `store` | 1074 | — | SubstituteScheduleService::effectiveInstructorUserId |
| `backend/app/Http/Controllers/LearningRecordController.php:1267` `update` | 1286 | 1287 | — |
| `backend/app/Http/Controllers/LearningRecordController.php:1373` `updateTeacher` | 1435 | — | OccurrenceAssignmentService::enabledFor, OccurrenceAssignmentService::onLeave, OccurrenceAssignmentService::futureConflicts |
| `backend/app/Http/Controllers/LearningRecordController.php:1498` `destroy` | 1512, 1534 | — | ApprovalSessionSyncService::syncOnRollback |
| `backend/app/Http/Controllers/LearningRecordController.php:1542` `approve` | 1553 | — | ApprovalSessionSyncService::syncOnApprove, NotificationSyncService::sync |
| `backend/app/Http/Controllers/LearningRecordController.php:1589` `rollbackApproval` | 1600, 1624 | 1605 | ApprovalSessionSyncService::syncOnRollback |
| `backend/app/Http/Controllers/LearningRecordController.php:1635` `batchApprove` | 1715 | — | ApprovalSessionSyncService::syncOnApprove, NotificationSyncService::sync |
| `backend/app/Http/Controllers/LearningRecordController.php:1749` `batchReject` | 1773 | — | — |
| `backend/app/Http/Controllers/LearningRecordController.php:1787` `batchRequestChanges` | 1811 | — | — |
| `backend/app/Http/Controllers/LearningRecordController.php:1825` `backdoorApprove` | 1844 | — | — |
| `backend/app/Http/Controllers/LearningRecordController.php:1915` `bulkBackdoorApprove` | 1963 | — | — |
| `backend/app/Http/Controllers/LearningRecordTeacherCommentController.php:15` `upsert` | 37 | 38 | — |
| `backend/app/Http/Controllers/ParentPortalController.php:321` `loginWithLine` | 372 | 376 | — |
| `backend/app/Http/Controllers/ParentPortalController.php:1823` `requestLeave` | 1870 | 1879 | — |
| `backend/app/Http/Controllers/PaymentReportController.php:170` `submit` | 208 | 209 | — |
| `backend/app/Http/Controllers/PaymentReportController.php:351` `confirm` | 364 | 417 | — |
| `backend/app/Http/Controllers/PaymentReportController.php:620` `directorRecord` | 700 | 702, 725, 797 | — |
| `backend/app/Http/Controllers/PaymentReportController.php:834` `recordLegacyFlagBackfill` | 844 | 861 | — |
| `backend/app/Http/Controllers/PaymentReportController.php:900` `clearLegacyFlag` | 916 | — | — |
| `backend/app/Http/Controllers/PaymentReportController.php:1017` `legacyFlagCourseIds` | — | 1024 | — |
| `backend/app/Http/Controllers/PaymentReportController.php:1037` `lockLegacyFlagCourses` | — | 1039, 1040, 1047, 1049 | — |
| `backend/app/Http/Controllers/PaymentReportController.php:1103` `recordCorrectionReissue` | 1106 | 1108, 1109, 1125 | — |
| `backend/app/Http/Controllers/PaymentReportController.php:1189` `recordFreeGiftBill` | 1191 | 1192, 1225 | — |
| `backend/app/Http/Controllers/PaymentReportController.php:1429` `lockCourseThenReport` | — | 1431, 1432 | — |
| `backend/app/Http/Controllers/PaymentReportController.php:1503` `lockPackageForCourse` | — | 1510 | — |
| `backend/app/Http/Controllers/PaymentReportController.php:1615` `invoiceProjection` | — | 1617 | — |
| `backend/app/Http/Controllers/PaymentReportController.php:1658` `pickOpenInvoice` | — | 1662 | — |
| `backend/app/Http/Controllers/PaymentReportController.php:1809` `void` | 1835 | 1866, 1872 | — |
| `backend/app/Http/Controllers/ProfileController.php:564` `bulkTeachers` | 693 | — | — |
| `backend/app/Http/Controllers/ProfileController.php:795` `update` | 869, 910 | — | TeacherScopeService::replaceScopes |
| `backend/app/Http/Controllers/ProfileController.php:1012` `decideCampusLink` | 1032 | — | — |
| `backend/app/Http/Controllers/ProfileController.php:1067` `resetPassword` | 1091 | — | — |
| `backend/app/Http/Controllers/ProfileController.php:1108` `destroy` | 1133 | — | — |
| `backend/app/Http/Controllers/ResetDataController.php:14` `__invoke` | 20 | — | — |
| `backend/app/Http/Controllers/ScheduleController.php:129` `lockStudentForWrite` | — | 131 | — |
| `backend/app/Http/Controllers/ScheduleController.php:134` `store` | 429, 479, 556 | 482, 500 | CourseLeaveCascadeService::applyLeaveCascade, CourseLeaveCascadeService::sessionIdAt, CourseLeaveCascadeService::leaveOutcomeForCourse |
| `backend/app/Http/Controllers/ScheduleController.php:568` `undoLeave` | 596 | — | CourseLeaveCascadeService::undoLeaveCascade, CourseLeaveCascadeService::sessionIdAt |
| `backend/app/Http/Controllers/ScheduleController.php:629` `undoLeaveBySession` | 642 | 643, 662 | CourseLeaveCascadeSuccessorShiftService::shiftTail, CourseLeaveCascadeService::undoLeaveCascade, CourseLeaveCascadeSuccessorShiftService::assertUndoAllowed, CourseLeaveCascadeService::fetchCourseSessionRows |
| `backend/app/Http/Controllers/ScheduleController.php:722` `retroLeave` | 744 | 745, 765, 797, 812 | CourseLeaveCascadeService::applyLeaveCascade, CourseLeaveCascadeService::leaveOutcomeForCourse, SessionDeductionService::reverseForSession, CourseLeaveCascadeService::appendNote, CourseLeaveCascadeService::appendTailAfterLeave, SessionDeductionService::recomputeCounters |
| `backend/app/Http/Controllers/ScheduleController.php:921` `leaveBySession` | 930 | 931, 937 | CourseLeaveCascadeService::applyLeaveCascade, CourseLeaveCascadeService::recordLeaveMarker, CourseLeaveCascadeService::leaveOutcomeForCourse |
| `backend/app/Http/Controllers/ScheduleController.php:993` `bulkHolidayLeave` | 1055 | — | CourseLeaveCascadeService::applyLeaveCascade |
| `backend/app/Http/Controllers/ScheduleController.php:1114` `update` | 1218 | — | — |
| `backend/app/Http/Controllers/ScheduleController.php:1390` `applyLeaveScheduleUpdate` | 1397, 1434, 1437, 1440 | — | CourseLeaveCascadeService::applyLeaveCascade, CourseLeaveCascadeService::sessionIdAt, CourseLeaveCascadeService::leaveOutcomeForCourse |
| `backend/app/Http/Controllers/ScheduleController.php:1468` `cancelMakeup` | 1494 | — | — |
| `backend/app/Http/Controllers/StudentClassController.php:1419` `store` | 1532 | — | — |
| `backend/app/Http/Controllers/StudentClassController.php:1647` `update` | 1980 | — | OccurrenceAssignmentService::enabledFor, TeacherScopeService::check |
| `backend/app/Http/Controllers/StudentClassController.php:2554` `paymentReviewCheck` | 2561 | 2562 | — |
| `backend/app/Http/Controllers/StudentClassController.php:2606` `correctUnpaidDateModeContract` | 2704 | 2705, 2729 | SessionDeductionService::batchExpectedUsedSessionDiagnostics, SessionDeductionService::recomputeCounters |
| `backend/app/Http/Controllers/StudentClassController.php:2810` `chargeCorrection` | 2881 | 2882, 2898 | — |
| `backend/app/Http/Controllers/StudentClassController.php:3007` `splitContract` | 3016 | 3018, 3064, 3085 | SessionDeductionService::recomputeCounters |
| `backend/app/Http/Controllers/StudentClassController.php:3157` `confirmPayment` | 3172 | 3173 | — |
| `backend/app/Http/Controllers/StudentClassController.php:3189` `continueTutoring` | 3201 | 3202, 3204, 3218 | SessionDeductionService::syncCounters |
| `backend/app/Http/Controllers/StudentClassController.php:3793` `addSession` | 3844 | 3859, 3864 | ApprovalSessionSyncService::syncOnApprove, ApprovalSessionSyncService::hasDeductedSignIn |
| `backend/app/Http/Controllers/StudentClassController.php:4493` `transferSessions` | 4507 | 4508, 4509 | SessionDeductionService::batchExpectedUsedSessionDiagnostics, SessionDeductionService::recomputeCounters |
| `backend/app/Http/Controllers/StudentClassController.php:4935` `destroy` | 4957 | 4960, 4964 | — |
| `backend/app/Http/Controllers/StudentClassController.php:5171` `prepareSplitContractPlan` | — | 5227 | SessionDeductionService::batchObservedUsedSessions |
| `backend/app/Http/Controllers/StudentClassController.php:5472` `transferByCutover` | 5474, 5477, 5481 | — | — |
| `backend/app/Http/Controllers/StudentClassController.php:5512` `applyTransfer` | — | 5514, 5526 | SessionDeductionService::batchObservedUsedSessions, SessionDeductionService::recomputeCounters |
| `backend/app/Http/Controllers/StudentClassController.php:5647` `afterTransferMoney` | — | 5655 | — |
| `backend/app/Http/Controllers/StudentClassController.php:5665` `linkTransferGroup` | — | 5676 | — |
| `backend/app/Http/Controllers/StudentClassController.php:5698` `paidTransferPlan` | — | 5714 | — |
| `backend/app/Http/Controllers/StudentClassController.php:5749` `recordPaidContractTransfer` | — | 5758 | — |
| `backend/app/Http/Controllers/StudentClassController.php:6620` `withTransferHint` | 6660, 6669 | — | — |
| `backend/app/Http/Controllers/StudentClassController.php:6825` `togglePause` | 6851, 6855, 6861, 6868, 6903, 6936, 6945, 6964, 6986, 6995 | 6853 | — |
| `backend/app/Http/Controllers/StudentController.php:337` `purgeStudentRecords` | 360 | 362, 365, 369, 370 | — |
| `backend/app/Http/Controllers/StudentController.php:480` `bulkDestroy` | 533 | 534, 535 | — |
| `backend/app/Http/Controllers/StudentController.php:651` `removeLineBinding` | 667 | — | — |
| `backend/app/Http/Controllers/SubstituteController.php:163` `undo` | 251 | — | OccurrenceAssignmentService::handles |
| `backend/app/Http/Controllers/TeacherLeaveController.php:125` `batchSubstitute` | 173 | — | — |
| `backend/app/Models/CoursePackage.php:82` `computeRemainingFromLedger` | — | 86 | — |
| `backend/app/Models/StudentClass.php:236` `terminalClosureInDb` | — | 238 | — |
| `backend/app/Operations/PopOperationService.php:25` `createDraft` | 54 | 57 | — |
| `backend/app/Operations/PopOperationService.php:139` `recordApproval` | 210 | 211 | — |
| `backend/app/Operations/PopOperationService.php:322` `runDryRun` | 331 | 332 | — |
| `backend/app/Operations/PopOperationService.php:344` `run` | 357 | 358 | — |
| `backend/app/Operations/PopOperationService.php:364` `runPhase` | 374, 450, 461, 464 | 375 | — |
| `backend/app/Operations/PopOperationService.php:1036` `quorumRoles` | 1045 | — | — |
| `backend/app/Operations/Strategies/BillingPeriodRekeyStrategy.php:85` `lockContractThenBill` | — | 90, 92 | — |
| `backend/app/Operations/Strategies/BillingPeriodRekeyStrategy.php:96` `rekeyOne` | 100 | — | — |
| `backend/app/Operations/Strategies/BillingPeriodRekeyStrategy.php:191` `rollback` | 191 | — | — |
| `backend/app/Operations/Strategies/BillingPeriodRekeyStrategy.php:218` `restoreOne` | 222 | — | — |
| `backend/app/Operations/Strategies/ContractChargeCorrectionStrategy.php:58` `execute` | 71 | 72 | — |
| `backend/app/Operations/Strategies/ContractChargeCorrectionStrategy.php:104` `rollback` | 104, 113 | 116 | — |
| `backend/app/Operations/Strategies/CourseContractRepairStrategy.php:106` `execute` | 109 | 122, 123 | — |
| `backend/app/Operations/Strategies/CourseContractRepairStrategy.php:166` `rollback` | 166, 169 | — | SessionDeductionService::recomputeCounters |
| `backend/app/Operations/Strategies/CourseContractRepairStrategy.php:260` `updateInvoice` | — | 263, 266 | — |
| `backend/app/Operations/Strategies/CourseContractRepairStrategy.php:272` `restoreInvoice` | — | 274 | — |
| `backend/app/Operations/Strategies/DuplicateAttendedCancelStrategy.php:64` `execute` | 72 | 84, 85, 86, 98 | — |
| `backend/app/Operations/Strategies/DuplicateAttendedCancelStrategy.php:141` `rollback` | 141, 147 | 151, 168 | SessionDeductionService::recomputeCounters, PackageDeductionService::recomputeCounters |
| `backend/app/Operations/Strategies/DuplicateAttendedCancelStrategy.php:183` `executeRestore` | 190 | 186 | — |
| `backend/app/Operations/Strategies/DuplicateAttendedCancelStrategy.php:197` `cancelOne` | — | 199 | CourseLeaveCascadeService::voidLiveArtifactsForNonAttendance, SessionDeductionService::reverseForSession, SessionDeductionService::recomputeCounters |
| `backend/app/Operations/Strategies/DuplicateHistoryPinRepairStrategy.php:46` `execute` | 55 | 57 | — |
| `backend/app/Operations/Strategies/DuplicateHistoryPinRepairStrategy.php:89` `rollback` | 89, 96 | — | — |
| `backend/app/Operations/Strategies/ForwardExtraCancelStrategy.php:66` `execute` | 78, 85, 95 | 80, 100, 101 | — |
| `backend/app/Operations/Strategies/ForwardExtraCancelStrategy.php:151` `rollback` | 151, 158 | 163 | — |
| `backend/app/Operations/Strategies/ForwardOverdeductReassignStrategy.php:65` `execute` | 76, 85, 94 | — | — |
| `backend/app/Operations/Strategies/ForwardOverdeductReassignStrategy.php:146` `rollback` | 146, 153 | — | — |
| `backend/app/Operations/Strategies/ForwardOverdeductReassignStrategy.php:197` `lockCourses` | — | 199 | — |
| `backend/app/Operations/Strategies/Invoice1997StaleValuesStrategy.php:64` `execute` | 79 | — | — |
| `backend/app/Operations/Strategies/Invoice1997StaleValuesStrategy.php:120` `rollback` | 120, 128 | — | — |
| `backend/app/Operations/Strategies/Invoice1997StaleValuesStrategy.php:178` `inspect` | — | 182, 184, 186 | — |
| `backend/app/Operations/Strategies/InvoiceHeaderItemRepairStrategy.php:48` `execute` | 61 | — | — |
| `backend/app/Operations/Strategies/InvoiceHeaderItemRepairStrategy.php:110` `rollback` | 110, 117 | — | — |
| `backend/app/Operations/Strategies/InvoiceHeaderItemRepairStrategy.php:155` `inspect` | — | 159 | — |
| `backend/app/Operations/Strategies/MonthlyAccountingCorrectionStrategy.php:58` `rollback` | 58, 60 | — | — |
| `backend/app/Operations/Strategies/MonthlyContractSplitStrategy.php:54` `rollback` | 54, 56 | — | — |
| `backend/app/Operations/Strategies/MuzhaChenBillingCatchupStrategy.php:58` `execute` | 68 | — | — |
| `backend/app/Operations/Strategies/MuzhaChenBillingCatchupStrategy.php:122` `rollback` | 122, 130 | 135, 136 | — |
| `backend/app/Operations/Strategies/MuzhaChenBillingCatchupStrategy.php:180` `restoreOrphan` | — | 182 | — |
| `backend/app/Operations/Strategies/MuzhaChenBillingCatchupStrategy.php:219` `inspect` | — | 221 | — |
| `backend/app/Operations/Strategies/MuzhaFixedScheduleStrategy.php:67` `execute` | 72 | — | — |
| `backend/app/Operations/Strategies/MuzhaFixedScheduleStrategy.php:124` `rollback` | 124, 130 | — | — |
| `backend/app/Operations/Strategies/MuzhaFixedScheduleStrategy.php:163` `inspect` | — | 173, 191 | — |
| `backend/app/Operations/Strategies/PackageLedgerNetRepairStrategy.php:68` `execute` | 77 | — | SessionDeductionService::reverseForSession |
| `backend/app/Operations/Strategies/PackageLedgerNetRepairStrategy.php:147` `rollback` | 147, 160 | — | — |
| `backend/app/Operations/Strategies/PackageLedgerNetRepairStrategy.php:206` `lockPackages` | — | 208, 209 | — |
| `backend/app/Operations/Strategies/PackageLedgerNetRepairStrategy.php:223` `recompute` | — | 228 | — |
| `backend/app/Operations/Strategies/PackageLedgerNetRepairStrategy.php:245` `inspect` | — | 247 | — |
| `backend/app/Operations/Strategies/PastScheduledSessionsCancelStrategy.php:59` `execute` | 75 | — | — |
| `backend/app/Operations/Strategies/PastScheduledSessionsCancelStrategy.php:109` `rollback` | 109, 116 | — | — |
| `backend/app/Operations/Strategies/PastScheduledSessionsCancelStrategy.php:152` `inspect` | — | 156, 159 | — |
| `backend/app/Operations/Strategies/PaymentReportPendingReturnStrategy.php:43` `execute` | 46 | — | — |
| `backend/app/Operations/Strategies/PaymentReportPendingReturnStrategy.php:69` `rollback` | 69, 71 | — | — |
| `backend/app/Operations/Strategies/PaymentReportPendingReturnStrategy.php:96` `capture` | — | 102, 107 | — |
| `backend/app/Operations/Strategies/PaymentReportPendingReturnStrategy.php:136` `request` | — | 139 | — |
| `backend/app/Operations/Strategies/SharedPoolTrimStrategy.php:65` `execute` | 77 | 99, 100 | — |
| `backend/app/Operations/Strategies/SharedPoolTrimStrategy.php:141` `rollback` | 141, 155 | — | — |
| `backend/app/Operations/Strategies/SharedPoolTrimStrategy.php:205` `restoreAction` | — | 211 | — |
| `backend/app/Operations/Strategies/TaughtLessonSignInRepairStrategy.php:72` `execute` | 82, 92, 103 | 84, 105 | — |
| `backend/app/Operations/Strategies/TaughtLessonSignInRepairStrategy.php:182` `rollback` | 182, 189 | — | — |
| `backend/app/Operations/Strategies/Td076CollisionKeepersStrategy.php:147` `apply` | — | 151 | — |
| `backend/app/Operations/Strategies/Td076CollisionKeepersStrategy.php:162` `undo` | — | 164 | — |
| `backend/app/Operations/Strategies/Td076HistoryPinsStrategy.php:135` `undo` | — | 137 | — |
| `backend/app/Operations/Strategies/UnbilledBacklogCatchupStrategy.php:51` `execute` | 62 | 63 | — |
| `backend/app/Operations/Strategies/UnbilledBacklogCatchupStrategy.php:107` `rollback` | 107, 117 | 122, 123 | — |
| `backend/app/Operations/Strategies/UnpaidHiddenClosuresStrategy.php:40` `execute` | 58 | — | — |
| `backend/app/Operations/Strategies/UnpaidHiddenClosuresStrategy.php:85` `rollback` | 85, 92 | — | — |
| `backend/app/Operations/Strategies/UnpaidHiddenClosuresStrategy.php:131` `inspect` | — | 135, 138 | — |
| `backend/app/Operations/Strategies/UnpaidInvoiceChargeAlignmentStrategy.php:44` `execute` | 47 | 54, 55, 56 | — |
| `backend/app/Operations/Strategies/UnpaidInvoiceChargeAlignmentStrategy.php:83` `rollback` | 83, 85 | — | — |
| `backend/app/Operations/Strategies/UnpaidInvoiceChargeAlignmentStrategy.php:102` `capture` | — | 107, 112 | — |
| `backend/app/Operations/Strategies/UnpaidInvoiceChargeAlignmentStrategy.php:142` `request` | — | 145 | — |
| `backend/app/Services/ApprovalSessionSyncService.php:74` `payableSignIn` | 78 | 79, 80, 107 | — |
| `backend/app/Services/ApprovalSessionSyncService.php:126` `syncOnRollback` | 126 | — | SessionDeductionService::reverseForSession, SessionDeductionService::recomputeCounters |
| `backend/app/Services/AssessmentAttemptService.php:12` `configureQuestions` | 37 | — | — |
| `backend/app/Services/AssessmentAttemptService.php:88` `createAttempt` | 90 | 95 | — |
| `backend/app/Services/AssessmentAttemptService.php:149` `saveAnswers` | 151 | — | — |
| `backend/app/Services/AssessmentAttemptService.php:171` `submit` | 173 | — | — |
| `backend/app/Services/AssessmentAttemptService.php:214` `review` | 216 | — | — |
| `backend/app/Services/AssessmentAttemptService.php:242` `lockAttempt` | — | 244 | — |
| `backend/app/Services/AttendanceLearningRecordIntegrityService.php:132` `repair` | 152, 168 | 153, 169 | CourseLeaveCascadeService::voidLiveArtifactsForNonAttendance |
| `backend/app/Services/Billing/AgreedFeeCorrection.php:21` `run` | 25 | 27, 29, 56, 58, 59 | — |
| `backend/app/Services/Billing/BillingCorrection.php:130` `applyCountCorrection` | 134 | 136, 154, 176, 182, 188, 191, 227 | SessionDeductionService::batchExpectedUsedSessionDiagnostics, SessionDeductionService::recomputeCounters |
| `backend/app/Services/Billing/BillingRefundService.php:73` `refund` | 75 | 76, 104 | — |
| `backend/app/Services/Billing/BillingRefundService.php:154` `invoices` | — | 158 | — |
| `backend/app/Services/Billing/ContractRenewal.php:40` `priorFeeBlocker` | — | 60 | — |
| `backend/app/Services/Billing/ContractRenewal.php:742` `purchaseBatch` | 744 | — | SessionDeductionService::syncCounters |
| `backend/app/Services/Billing/ContractRenewal.php:954` `convertTrial` | 956 | — | — |
| `backend/app/Services/Billing/ContractRenewal.php:1059` `confirm` | 1061 | — | — |
| `backend/app/Services/Billing/ContractRenewal.php:1112` `lockCourse` | — | 1115 | — |
| `backend/app/Services/Billing/ContractRenewal.php:1140` `renewMonthly` | 1142 | — | — |
| `backend/app/Services/Billing/InvoiceCorrectionLink.php:14` `precheck` | — | 24 | — |
| `backend/app/Services/Billing/InvoiceIssuer.php:33` `issue` | — | 43, 48 | — |
| `backend/app/Services/Billing/OrdinaryCloseActivityCensus.php:30` `captureLockedUnsafe` | — | 38, 46, 67, 109, 119, 146, 170, 173, 181 | — |
| `backend/app/Services/Billing/OrdinaryCloseActivityCensus.php:214` `selectOwned` | — | 220 | — |
| `backend/app/Services/Billing/OrdinaryCloseBillingCensus.php:29` `captureLocked` | — | 39, 40 | — |
| `backend/app/Services/Billing/OrdinaryCloseBillingCensus.php:112` `read` | — | 119 | — |
| `backend/app/Services/Billing/OrdinaryCloseCapture.php:29` `captureLocked` | 31 | 33, 40 | — |
| `backend/app/Services/Billing/OrdinaryCloseCapture.php:89` `describeCloseLocked` | 91 | 97 | — |
| `backend/app/Services/Billing/OrdinaryCloseCapture.php:198` `rows` | — | 202 | — |
| `backend/app/Services/Billing/OrdinaryCloseCapture.php:241` `requireTransaction` | 241 | — | — |
| `backend/app/Services/Billing/OrdinaryCloseEvidence.php:31` `append` | — | 49 | — |
| `backend/app/Services/Billing/PaymentReversalRestatement.php:29` `execute` | 33 | — | — |
| `backend/app/Services/Billing/PaymentReversalRestatement.php:63` `rollback` | 63, 65 | — | — |
| `backend/app/Services/Billing/PaymentReversalRestatement.php:158` `assertReplay` | — | 164 | — |
| `backend/app/Services/Billing/PaymentReversalRestatement.php:174` `request` | — | 176 | — |
| `backend/app/Services/Billing/PaymentReversalRestatement.php:199` `authorize` | — | 208, 209 | — |
| `backend/app/Services/Billing/PaymentReversalRestatement.php:214` `capture` | — | 218 | — |
| `backend/app/Services/Billing/SiblingContractTransferService.php:63` `run` | 65, 68, 72 | — | — |
| `backend/app/Services/Billing/SiblingContractTransferService.php:77` `apply` | — | 84, 85 | SessionDeductionService::batchExpectedUsedSessionDiagnostics |
| `backend/app/Services/Billing/SiblingContractTransferService.php:189` `assertSimplePaidContract` | — | 218 | — |
| `backend/app/Services/BillingModeConversionArchiveService.php:31` `archiveAfterScheduleModeChange` | 60 | 72, 114 | — |
| `backend/app/Services/ClassSessionContractReflowService.php:35` `move` | 72 | — | — |
| `backend/app/Services/ClassSessionMaterializationService.php:86` `upsertSlot` | 97 | 104, 114 | — |
| `backend/app/Services/ClassSessionMaterializationService.php:210` `assertStudentSlotAvailable` | — | 265, 333, 339 | — |
| `backend/app/Services/ContractAmendmentService.php:56` `execute` | 62 | 63, 76, 89 | — |
| `backend/app/Services/ContractAmendmentService.php:277` `revert` | 283 | 284 | — |
| `backend/app/Services/ContractAmendmentService.php:396` `revertibleSessions` | — | 400 | — |
| `backend/app/Services/ContractAmendmentService.php:403` `revertibleSchedules` | — | 406 | — |
| `backend/app/Services/CourseContinuityService.php:54` `createGroup` | 63 | — | — |
| `backend/app/Services/CourseContinuityService.php:89` `addMember` | 93 | — | — |
| `backend/app/Services/CourseLeaveCascadeService.php:231` `applyLeaveCascade` | — | 233, 241 | — |
| `backend/app/Services/CourseLeaveCascadeService.php:396` `applyExplicitCoursePauseShift` | — | 398, 404 | — |
| `backend/app/Services/CourseLeaveCascadeService.php:1052` `undoLeaveCascade` | — | 1054, 1063 | CourseLeaveCascadeSuccessorShiftService::shiftTail, CourseLeaveCascadeSuccessorShiftService::assertUndoAllowed |
| `backend/app/Services/CourseLeaveCascadeSuccessorShiftService.php:40` `assertUndoAllowed` | — | 43, 61, 81, 82, 101, 102 | — |
| `backend/app/Services/CourseLeaveCascadeSuccessorShiftService.php:114` `shiftTail` | — | 117 | — |
| `backend/app/Services/CourseLeaveCascadeSuccessorShiftService.php:151` `apply` | 156 | — | CourseLeaveCascadeService::appendNote, CourseLeaveCascadeService::markSessionLeaveOnly, CourseLeaveCascadeService::recordLeaveMarker, CourseLeaveCascadeService::syncLearningRecordSessionDate |
| `backend/app/Services/CourseLeaveCascadeSuccessorShiftService.php:224` `plan` | — | 227, 228, 292 | CourseLeaveCascadeService::previewLeaveCascadeForCourse |
| `backend/app/Services/CourseLeaveCascadeSuccessorShiftService.php:365` `successor` | — | 388 | — |
| `backend/app/Services/CourseLeaveCascadeSuccessorShiftService.php:409` `sessions` | — | 413 | — |
| `backend/app/Services/CourseLeaveCascadeSuccessorShiftService.php:427` `evidenceReason` | — | 429 | — |
| `backend/app/Services/EnrollmentService.php:51` `store` | 740 | 766, 786 | TeacherScopeService::check, SessionDeductionService::syncCounters |
| `backend/app/Services/EnrollmentService.php:1213` `guardRenewalConfirmedOverlap` | — | 1235 | — |
| `backend/app/Services/GradePromotionService.php:116` `confirm` | 192 | 194, 224 | — |
| `backend/app/Services/ManualSessionBookingService.php:234` `create` | 243 | 244, 252 | — |
| `backend/app/Services/MonthlyAccountingCorrectionService.php:142` `execute` | 146 | 147 | PopOperationService::canonicalParameters |
| `backend/app/Services/MonthlyAccountingCorrectionService.php:213` `rollback` | 213, 215, 223 | 216 | — |
| `backend/app/Services/MonthlyAccountingCorrectionService.php:296` `course` | — | 299 | — |
| `backend/app/Services/MonthlyContractCorrectionService.php:139` `execute` | 142 | — | — |
| `backend/app/Services/MonthlyContractCorrectionService.php:198` `rollback` | 198, 200 | 201 | — |
| `backend/app/Services/MonthlyContractCorrectionService.php:226` `graph` | — | 229 | — |
| `backend/app/Services/MonthlyContractCorrectionService.php:293` `findCourse` | — | 296 | — |
| `backend/app/Services/MonthlyRenewalPeriodService.php:81` `adopt` | — | 86, 87, 88 | SessionDeductionService::recomputeCounters |
| `backend/app/Services/NotificationSyncService.php:43` `sync` | 75 | 126 | — |
| `backend/app/Services/OccurrenceAssignmentService.php:131` `assignTeacher` | 138 | 146, 148 | RescheduleSessionService::freezeOccurrenceIdentity |
| `backend/app/Services/OccurrenceAssignmentService.php:195` `restoreContractTeacher` | 197 | 207 | — |
| `backend/app/Services/OccurrenceAssignmentService.php:245` `lockOccurrence` | — | 247 | — |
| `backend/app/Services/OccurrenceAssignmentService.php:271` `findLive` | — | 273 | — |
| `backend/app/Services/PackageDeductionService.php:26` `deductForSession` | 39 | — | — |
| `backend/app/Services/PackageDeductionService.php:58` `reverseForSession` | 71 | — | — |
| `backend/app/Services/PackageDeductionService.php:123` `lockPackage` | — | 125 | — |
| `backend/app/Services/PackageDeductionService.php:132` `currentLedger` | — | 134 | — |
| `backend/app/Services/PackageDeductionService.php:163` `recomputeCounters` | 165 | 166 | — |
| `backend/app/Services/PackageDeductionService.php:191` `syncFromStudentClassDeduction` | 209 | — | — |
| `backend/app/Services/PackageDeductionService.php:225` `deductUnderSlotRule` | 228 | — | — |
| `backend/app/Services/PackageDeductionService.php:246` `reslotRetimedSession` | 256 | — | — |
| `backend/app/Services/PackageDeductionService.php:292` `lockForRetime` | — | 295 | — |
| `backend/app/Services/PackageDeductionService.php:406` `rebuildLedgerFromSessions` | 461 | 477 | — |
| `backend/app/Services/PackageDeductionService.php:518` `fullRecompute` | 525 | 526 | — |
| `backend/app/Services/ParentPortalTestFixtureService.php:18` `ensureFixture` | 24 | 27 | — |
| `backend/app/Services/ParentPortalTestFixtureService.php:138` `createReusableSession` | 140 | — | — |
| `backend/app/Services/RecordStudentSwipe.php:29` `handle` | 33 | — | — |
| `backend/app/Services/RecordStudentSwipe.php:41` `record` | — | 77, 82, 98 | StudentPresenceBackfillService::backfill, AttendanceEffectsService::assertCanRecordAttendance, AttendanceEffectsService::resolveSwipeStatus, AttendanceEffectsService::applySessionStatus, SessionDeductionService::deductOnAttendance, StudentPresenceBackfillService::alertIfNotDeducted |
| `backend/app/Services/RescheduleSessionService.php:35` `execute` | 37 | 46, 190 | — |
| `backend/app/Services/RescheduleSessionService.php:245` `findSession` | — | 253 | — |
| `backend/app/Services/RescheduleSessionService.php:262` `findCommittedReplay` | — | 277, 287, 296 | — |
| `backend/app/Services/RescheduleSessionService.php:399` `ensureScheduleException` | — | 418, 432, 462 | — |
| `backend/app/Services/RescheduleSessionService.php:546` `syncExistingScheduleChain` | — | 561, 576 | — |
| `backend/app/Services/RescheduleSessionService.php:595` `cancelAutoMaterializedDuplicateSession` | — | 615 | — |
| `backend/app/Services/ScheduleGuardService.php:230` `validateCourseOccurrence` | — | 239, 267 | SubstituteScheduleService::teacherForOccurrence |
| `backend/app/Services/ScheduleGuardService.php:1266` `loadRoom` | — | 1269 | — |
| `backend/app/Services/ScheduleGuardService.php:1562` `buildTeacherDateOccupancyEntries` | — | 1596, 1645 | SubstituteScheduleService::occurrenceKey, SubstituteScheduleService::teachersForOccurrences, SubstituteScheduleService::makeupOccurrencesTaughtBy |
| `backend/app/Services/ScheduleGuardService.php:1776` `loadCourseTeacherMeta` | — | 1783 | — |
| `backend/app/Services/Scheduling/ClassSessionContractReassigner.php:28` `reassign` | 30, 52, 66, 100, 116, 119 | 32, 34 | SessionDeductionService::recomputeCounters |
| `backend/app/Services/Scheduling/ContractSessionSchedule.php:1498` `syncFutureScheduledSessionTimes` | 1513 | — | — |
| `backend/app/Services/Scheduling/EnsureSessionHorizonService.php:36` `ensure` | 162 | — | — |
| `backend/app/Services/SessionContractRecoveryService.php:19` `recoverAndTransfer` | 26 | 27, 28, 36 | SessionDeductionService::recomputeCounters |
| `backend/app/Services/SessionContractRecoveryService.php:143` `moveEvidence` | — | 147, 163 | — |
| `backend/app/Services/SessionDeductionService.php:387` `deductForSession` | 398 | 399 | PackageDeductionService::syncFromStudentClassDeduction |
| `backend/app/Services/SessionDeductionService.php:431` `reverseForSession` | 439 | 440, 456 | PackageDeductionService::syncFromStudentClassDeduction |
| `backend/app/Services/SessionDeductionService.php:486` `sessionLedgerNet` | — | 496 | — |
| `backend/app/Services/SessionDeductionService.php:504` `recomputeCounters` | 506 | 507 | — |
| `backend/app/Services/SessionDeductionService.php:649` `deductOnAttendance` | 667 | — | — |
| `backend/app/Services/SessionEntitlementTransferService.php:89` `transfer` | 100 | — | SessionDeductionService::recomputeCounters |
| `backend/app/Services/SessionEntitlementTransferService.php:151` `rollback` | 151, 153 | — | SessionDeductionService::recomputeCounters |
| `backend/app/Services/SessionEntitlementTransferService.php:321` `lockedStudentClass` | — | 323 | — |
| `backend/app/Services/SessionEntitlementTransferService.php:328` `lockedClassSession` | — | 330 | — |
| `backend/app/Services/SessionEntitlementTransferService.php:335` `lockedTransfer` | — | 337 | — |
| `backend/app/Services/SharedPackagePlanningService.php:27` `usedUpNotice` | — | 34 | — |
| `backend/app/Services/SharedPackagePlanningService.php:54` `sharedPoolExcess` | — | 65 | ClassSessionDeleteGuard::referencedSessionIds, ClassSessionDeleteGuard::datedScheduleSessionIds |
| `backend/app/Services/StaleScheduleExceptionFilter.php:49` `rejectStaleBatch` | — | 68 | — |
| `backend/app/Services/StudentPresenceBackfillService.php:33` `backfill` | 37 | — | — |
| `backend/app/Services/StudentPresenceBackfillService.php:40` `backfillLocked` | — | 66, 69, 77 | AttendanceEffectsService::applySessionStatus, SessionDeductionService::deductOnAttendance |
| `backend/app/Services/SubstituteScheduleService.php:66` `teachersForOccurrences` | — | 75, 86 | OccurrenceAssignmentService::enabledFor |
| `backend/app/Services/SubstituteScheduleService.php:255` `occurrence` | — | 261, 276, 312 | OccurrenceAssignmentService::enabledFor |
| `backend/app/Services/SubstituteScheduleService.php:328` `makeupOccurrencesTaughtBy` | — | 342, 344, 346, 348, 380 | — |
| `backend/app/Services/SubstituteScheduleService.php:386` `legacySubstituteUserId` | — | 408 | — |
| `backend/app/Services/SubstituteService.php:489` `createParentNotification` | — | 600 | — |
| `backend/app/Services/TeacherUserMergeService.php:60` `merge` | 64 | — | — |
| `backend/app/Services/UserEngagementXpAwardService.php:29` `awardOnLearningRecordApprovedIfEligible` | 55 | 58, 74 | — |
| `backend/app/Services/UserEngagementXpAwardService.php:92` `revokeLearningRecordApproved` | 99 | 108 | — |
| `backend/app/Support/LearningRecordMutableOwnership.php:76` `protectedRecordForSession` | — | 82 | — |

## 含 write call、無本地顯式 transaction／lock 的候選

含 `save/update/delete/create/insert/upsert/increment/decrement/forceDelete` 呼叫；可為 caller-owned transaction、helper 或 false positive。請勿把這欄當成 168 個確認缺陷。

| Source file | Methods / lines |
|---|---|
| `backend/app/Console/Commands/BackfillPaidFromInvoice.php` | `handle:18` |
| `backend/app/Console/Commands/BackfillSubstituteScheduleAnchors.php` | `handle:18` |
| `backend/app/Console/Commands/CleanupClassSessionIntraDuplicates.php` | `handle:32` |
| `backend/app/Console/Commands/FixOrphanScheduledSessions.php` | `handle:29` |
| `backend/app/Console/Commands/LearningRecordDriftCheck.php` | `applyFixes:248` |
| `backend/app/Console/Commands/LeaveMakeupEvidenceCloseout.php` | `cleanup:358` |
| `backend/app/Console/Commands/RepairDuplicateSessionSlots.php` | `applyAction:420` |
| `backend/app/Console/Commands/RepairYang1445ContractMove.php` | `apply:221` |
| `backend/app/Http/Controllers/AdmissionInquiryController.php` | `followUp:155`, `contact:173`, `recordResult:240`, `linkEnrollment:254`, `markLost:272` |
| `backend/app/Http/Controllers/AssessmentController.php` | `audit:701` |
| `backend/app/Http/Controllers/AttendanceController.php` | `applyAttendanceEffects:876`, `recordPendingSwipe:980`, `update:1114` |
| `backend/app/Http/Controllers/BackfillController.php` | `applyClassTypeToStudentClass:213` |
| `backend/app/Http/Controllers/BankReconciliationController.php` | `importCsv:73`, `reconcile:181` |
| `backend/app/Http/Controllers/BillingController.php` | `syncStudentClassPaidFromInvoice:671` |
| `backend/app/Http/Controllers/BillingNoteController.php` | `store:60` |
| `backend/app/Http/Controllers/ClassSessionController.php` | `handleRetroLeaveTransition:1511`, `voidAttendanceArtifacts:1537`, `applyTimeAndNoteUpdates:1577`, `syncScheduleExceptionTime:1641`, `cancelLinkedScheduleExceptions:1676`, `syncSessionChargeForTimeChange:1794`, `syncLearningRecordTime:1887`, `syncStudentSignInStatus:1942`, `createStudentClassResilient:2141`, `syncApprovedLearningRecord:2168` |
| `backend/app/Http/Controllers/DirectorAccountController.php` | `register:25` |
| `backend/app/Http/Controllers/ExamRoundController.php` | `syncSubjects:196`, `audit:689` |
| `backend/app/Http/Controllers/FeeLedgerController.php` | `storePayout:128` |
| `backend/app/Http/Controllers/FinanceController.php` | `parttimePayrollReopen:1540`, `parttimePayrollRulesUpdate:2346`, `parttimePayrollTeacherRulesUpdate:2504`, `parttimePayrollTeacherRulesDelete:2606`, `consolidatedSummary:2975` |
| `backend/app/Http/Controllers/ImportController.php` | `students:18`, `studentClasses:86`, `importStudentsFromHandle:152` |
| `backend/app/Http/Controllers/LearningRecordController.php` | `resurrectVoidedLearningRecord:199`, `updateLocked:1291`, `requestChanges:2156`, `reject:2179`, `syncRecordWithClassSession:2284` |
| `backend/app/Http/Controllers/LearningRecordFeedbackController.php` | `parentShow:21`, `parentReply:41`, `staffReply:88`, `dismissAwaiting:125`, `parentUpsert:164`, `markRead:285` |
| `backend/app/Http/Controllers/LearningRecordTeacherCommentController.php` | `markRead:61` |
| `backend/app/Http/Controllers/NotificationController.php` | `markAllRead:145`, `markTuitionPaid:224` |
| `backend/app/Http/Controllers/ParentFeedbackController.php` | `store:23`, `markRead:89`, `markReadByTeacher:162`, `reply:175` |
| `backend/app/Http/Controllers/ParentPortalController.php` | `setNotificationPreferences:1662`, `createSession:1977`, `resolveSession:2176` |
| `backend/app/Http/Controllers/PaymentReportController.php` | `dropLegacyPaidMarks:1060`, `createPendingReport:1083`, `directorRecordBatch:1283`, `confirmBatch:1347`, `markPackagePaid:1580`, `reject:1772` |
| `backend/app/Http/Controllers/PendingSwipeController.php` | `assignStudent:167`, `destroy:212` |
| `backend/app/Http/Controllers/ProfileController.php` | `store:428`, `applyTeacherRfidInput:1172` |
| `backend/app/Http/Controllers/ScheduleController.php` | `ensureClassSessionForScheduleData:1314`, `deleteLeaveMarkers:1380`, `destroy:1445` |
| `backend/app/Http/Controllers/ScheduleDiscrepancyController.php` | `store:17`, `updateStatus:256`, `withdraw:337` |
| `backend/app/Http/Controllers/StudentClassController.php` | `sync:2298`, `createManualSession:4219`, `syncLatestPaymentDateForCourse:5984`, `maybeRebuildSessionsAfterUpdate:6141`, `restorePauseCancelledSessions:7031` |
| `backend/app/Http/Controllers/StudentController.php` | `store:213`, `update:251`, `bindCard:564`, `unbindCard:596` |
| `backend/app/Http/Controllers/SubjectController.php` | `bootstrapCanonical:34`, `update:139`, `destroy:193` |
| `backend/app/Http/Controllers/SwipeRfidController.php` | `prunePhotos:217`, `handleTeacherSwipe:277` |
| `backend/app/Imports/StudentClassesImport.php` | `collection:14` |
| `backend/app/Models/BugReportContractLink.php` | `revoke:42` |
| `backend/app/Models/CoursePackage.php` | `recomputeCounters:93` |
| `backend/app/Observers/ClassSessionObserver.php` | `created:79`, `updated:164`, `deleted:191` |
| `backend/app/Operations/PopOperationService.php` | `recordPolicyDecision:120`, `record:1617` |
| `backend/app/Operations/Strategies/BillingPeriodRekeyStrategy.php` | `journal:127` |
| `backend/app/Operations/Strategies/CourseContractRepairStrategy.php` | `restoreRows:287` |
| `backend/app/Operations/Strategies/PaymentReportPendingReturnStrategy.php` | `persist:154` |
| `backend/app/Operations/Strategies/Td076CollisionKeepersStrategy.php` | `move:173` |
| `backend/app/Operations/Strategies/UnbilledBacklogCatchupStrategy.php` | `create:170` |
| `backend/app/Operations/Strategies/UnpaidInvoiceChargeAlignmentStrategy.php` | `persistProof:153` |
| `backend/app/Services/ApprovalSessionSyncService.php` | `syncOnApprove:27` |
| `backend/app/Services/AttendanceEffectsService.php` | `applySessionStatus:65` |
| `backend/app/Services/Billing/ContractRenewal.php` | `cancelFutureScheduledSessions:689`, `saveNewCourse:1125` |
| `backend/app/Services/Billing/InvoiceCorrectionLink.php` | `link:41` |
| `backend/app/Services/Billing/PaymentReversalRestatement.php` | `persistProof:188` |
| `backend/app/Services/Billing/SiblingContractTransferService.php` | `write:246` |
| `backend/app/Services/CourseContinuityService.php` | `unlinkMember:103`, `attachMember:122` |
| `backend/app/Services/CourseLeaveCascadeService.php` | `markSessionLeaveOnly:80`, `voidLiveArtifactsForNonAttendance:133`, `appendTailAfterLeave:759`, `shiftAndAppendAfterLeave:948`, `restoreLeaveSession:1225`, `syncLearningRecordSessionDate:1338` |
| `backend/app/Services/DunningService.php` | `tryCreateEvent:189` |
| `backend/app/Services/EnrollmentService.php` | `createStudentInline:1468`, `createStudentClassResilient:1978`, `syncApprovedLearningRecord:2005` |
| `backend/app/Services/ExceptionWorkflowCandidateGenerator.php` | `generate:21` |
| `backend/app/Services/FeedbackPushNotifier.php` | `resolveStaffNotification:59`, `dispatchToStaff:72` |
| `backend/app/Services/GradePromotionService.php` | `adminDateForYear:19` |
| `backend/app/Services/LearningRecordBackfillService.php` | `createPendingForSession:55`, `restoreVoidedForFillableSession:168` |
| `backend/app/Services/LearningRecordResurrectionPolicy.php` | `restoreEligibleForSession:70` |
| `backend/app/Services/LeaveAttendanceService.php` | `createClosedForSession:13` |
| `backend/app/Services/MonthlyAccountingCorrectionService.php` | `replaceReceipt:238`, `targetItem:279`, `item:287` |
| `backend/app/Services/MonthlyContractCorrectionService.php` | `recount:307` |
| `backend/app/Services/NotificationSyncService.php` | `recheckSourceResolvedTypes:177`, `retireTransferredTuition:282`, `reopenIfOwedChanged:386` |
| `backend/app/Services/OccurrenceAssignmentService.php` | `createRow:279`, `log:316` |
| `backend/app/Services/PackageDeductionService.php` | `writeLedgerRow:84` |
| `backend/app/Services/RescheduleSessionService.php` | `freezeOccurrenceIdentity:319`, `maybeDualWriteOccurrenceIdentity:359`, `resetFutureSession:640` |
| `backend/app/Services/Scheduling/ContractSessionSchedule.php` | `extendSessionsIfNeeded:487`, `cancelExcessScheduledSessionsFromRows:858`, `reconcileWeekTimeFieldsFromSessions:1685` |
| `backend/app/Services/Scheduling/ContractTeacherChangeCascade.php` | `pinPastSessionsToFormerTeacherAfterContractTeacherChange:166`, `syncFutureScheduleTeachersAfterContractTeacherChange:283`, `alignMutableLearningRecordTeachersAfterContractTeacherChange:338`, `clearUntaughtPastFalseHistoryPins:391` |
| `backend/app/Services/SubstituteService.php` | `refreshParentNotification:622`, `voidParentNotificationForSession:642` |
| `backend/app/Services/TeacherUserMergeService.php` | `dedupeScopedUserRows:140`, `dedupeOverlappingFk:164`, `dedupeTeacherSubjectLevels:185`, `dedupePayrollTeacherBranchRules:211`, `mergeTeacherMonthlyStats:232`, `dedupeUserNotificationPreferences:255`, `dedupeChatThreadMembers:271`, `mergeUserProfileFields:302`, `finalizeMergedUser:331`, `bulkRetarget:344` |
