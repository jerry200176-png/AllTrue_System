# 115.07 正職老師薪資要件：資料來源與操作

## 判定來源

| 要件 | Alltrue 來源 | 缺資料時 |
| --- | --- | --- |
| 每週 16 段／40 小時 | schedules 正課時段、TeacherSingIn 實際上下班 | 待人工確認 |
| 請假／補課 | ClassSession.Status 與 StudentSingIn.Status 的 leave 狀態；補課與假日假由薪資事件補充 | 待人工確認 |
| 官方活動／統一公休／假日曆 | teacher_payroll_events | 待人工確認 |
| 平日下午課 | schedules 正課時數，先扣每日 4 小時低消 | 依可取得的課表計算 |
| 科目數 | 已核准且未排除的 LearningRecord，另計已完成 tutoring | 無核准資料時待人工確認 |
| 升學成果／年度績優 | teacher_payroll_achievements，必須有證明與審核 | 待人工確認 |
| 扣除案件 | teacher_payroll_deductions，必須有主任確認及總部核准 | 待人工確認，不自動扣除 |

## 主任／總部輸入與審核 API

以下路徑均受登入、分校權限、密碼變更鎖與 PIN 保護：

- GET /api/v1/finance/teacher-eligibility/inputs
- POST /api/v1/finance/teacher-eligibility/events
- POST /api/v1/finance/teacher-eligibility/achievements
- POST /api/v1/finance/teacher-eligibility/deductions
- POST /api/v1/finance/teacher-eligibility/events/{id}/approve
- POST /api/v1/finance/teacher-eligibility/achievements/{id}/verify
- POST /api/v1/finance/teacher-eligibility/deductions/{id}/confirm
- POST /api/v1/finance/teacher-eligibility/deductions/{id}/approve

新建事件、成果或扣除一律是 pending；只有核准後才會進入報表。扣除案件的核准順序固定為「主任確認 → 總部核准」。分校主任不可寫入其他分校；總部可處理全分校資料。

## 總部仍須提供的業務資料

1. 115.07 起正式假日／統一公休清單，含日期、適用分校與是否計入 16 小時。
2. 每筆假日假抵扣或補課的實際時數與完成狀態。
3. 升學成果的學生名單、科目、證明文件、主任與總部審核結果、起訖月。
4. 年度績優的得獎年度與次年度適用起訖日；2026 年資料不追溯套用。
5. 扣除案件類型、事實說明、主任確認人／時間、總部核准人／時間與生效期間。

報表不會把上述資料缺漏當成 0 或直接判定不符合，會回傳「待人工確認」與缺少欄位。
