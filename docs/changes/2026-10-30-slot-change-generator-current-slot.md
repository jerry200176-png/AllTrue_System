## 2026-10-30 — fix(scheduling): 改課程設定時段後，之後預排與系統自動補建的堂次都用新時段（in-app #450）
<!-- release-notes: staff_update=staff-2026-10-30-slot-change-generator-current-slot -->
- 在課程設定改上課時間時，從今天起所有預排堂次都會改成新時間（已上課、請假與個別調課／代課的堂次不動）。系統自動向前補建堂次時，原本會照過去幾堂的舊時間補，現在改用課程目前的固定時段。個別調課用的是堂次的「合約例外」標記（IsContractException）；手動改到剛好等於新時段的堂次視為一般堂次，之後改時段仍會跟著動。不需 migration，回滾＝還原這個 PR。
