# Incident repair: 大安盧越 SC1513 unpaid rollback

**Date:** 2026-08-21  
**Authorization:** Founder verbal approve in Cursor chat (rollback after confirming 主任核帳誤標).  
**Workflow:** `.github/workflows/lu-yue-1513-unpaid-rollback.yml`

## Evidence (read-only diagnose run 32439596805)

| Entity | ID | Fact |
|--------|----|------|
| Student | 375 | 盧越 / CampusID 15 |
| Previous period SC | 1513 | Charge 12000, Rate 1500, Paid=1, 8/8/0 |
| Current period SC | 2828 | Charge 13200, Rate 1650, Paid=0 (do not touch) |
| Legacy SC | 325 | Paid=1 Stop=1 (do not touch) |
| Invoice | 1070 | Status=paid, 12000/12000 |
| Payment | 1049 | Amount 12000, Method=transfer, Note=主任核帳登記, created 2026-07-06 |

## Mutation (execute mode only)

1. Insert Payment Amount=-12000 Method=void for Invoice 1070  
2. Set Invoice 1070 Status=void, PaidAmount=0  
3. Set StudentClass 1513 Paid=0, PayDate=null  
4. Void any confirmed PaymentReport on SC 1513  

Abort if any precondition fails. Backup tables before write.
