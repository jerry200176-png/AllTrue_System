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

## Alltrue 操作入口

主任從「正職薪資要件」頁面即可完成資料補登與審核，不需要直接操作資料庫：

1. 在「資料補登與審核」選擇假日／公休／請假、升學成果／年度績優或扣除案件。
2. 填寫日期、老師、時數、證明／備註與生效期間後送出；新資料會以「待審核」保留。
3. 右側待辦清單可直接完成主任核准／確認；扣除案件再由總部執行第二階段核准。
4. 報表項目的「待人工確認」會列出實際缺少欄位，補登並完成審核後按重新整理即可重新計算。

這個流程採用成熟 HR 系統常見的「表單補登 → 狀態化待辦 → 分級審核 → 審核後才納入計算」模式，避免把未知資料誤當成 0 或直接扣薪。
