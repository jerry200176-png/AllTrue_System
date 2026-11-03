## 2026-11-04 — fix(billing): 合約卡「共 N 堂」只算要上的課（in-app #472）
<!-- release-notes: staff_update=staff-2026-11-04-contract-lesson-total -->
- `ContractCard.vue`: 共 N 堂 is now 已上 + 還沒上, including lessons not yet scheduled. Leave and cancelled lessons are shown apart as 請假／取消 N 次（不算堂數）. Before, 共 counted every listed lesson, including leave and cancelled ones, so 共 did not equal 已上 + 還沒上 (a 76-lesson contract read 共 84). No data or billing change.
