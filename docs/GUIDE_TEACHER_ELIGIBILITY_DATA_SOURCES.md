# 115.07 正職老師薪資要件：資料來源與操作

## 判定來源

| 要件 | Alltrue 來源 | 缺資料時 |
| --- | --- | --- |
| 每週 16 段／40 小時 | schedules 正課時段；實際工時以學生點名 `StudentSingIn` 對應的 `ClassSession` 時間為主，不依賴 TeacherSingIn | 學生點名來源不存在時待人工確認 |
| 請假／補課 | ClassSession.Status 與 StudentSingIn.Status 的 leave 狀態；補課與假日假由薪資事件補充 | 待人工確認 |
| 官方活動／統一公休／假日曆 | teacher_payroll_events | 待人工確認 |
| 平日下午課 | 只計固定存在於學生課表的常態正課；排除補課／臨時加課後先合併重疊區間，再扣每日 4 小時低消；17–22／16:30–22／16–22 分別換算 0.5／0.75／1 段，最高 5% | 缺少常態／補課分類時待補資料，不以實際到班猜測 |
| 科目數 | 已核准且未排除的 LearningRecord，另計已完成 tutoring | 無核准資料時待人工確認 |
| 升學成果／年度績優 | teacher_payroll_achievements，必須有證明與審核 | 待人工確認 |
| 扣除案件 | teacher_payroll_deductions，必須有主任確認及總部核准 | 待人工確認，不自動扣除 |

## 主任／總部輸入與審核 API

以下路徑均受登入、分校權限、密碼變更鎖與 PIN 保護：

- GET /api/v1/finance/teacher-eligibility/inputs
- POST /api/v1/finance/teacher-eligibility/events
- PUT /api/v1/finance/teacher-eligibility/events/{id}
- POST /api/v1/finance/teacher-eligibility/events/{id}/withdraw
- POST /api/v1/finance/teacher-eligibility/achievements
- PUT /api/v1/finance/teacher-eligibility/achievements/{id}
- POST /api/v1/finance/teacher-eligibility/achievements/{id}/withdraw
- POST /api/v1/finance/teacher-eligibility/deductions
- PUT /api/v1/finance/teacher-eligibility/deductions/{id}
- POST /api/v1/finance/teacher-eligibility/deductions/{id}/withdraw
- POST /api/v1/finance/teacher-eligibility/events/{id}/approve
- POST /api/v1/finance/teacher-eligibility/achievements/{id}/verify
- POST /api/v1/finance/teacher-eligibility/deductions/{id}/confirm
- POST /api/v1/finance/teacher-eligibility/deductions/{id}/approve

新建事件、成果或扣除一律是 pending；核准前可用 PUT 修改或 withdraw 撤回。只有核准後才會進入報表。扣除案件在主任確認後即不可再改。核准順序固定為「主任確認 → 總部核准」。分校主任不可寫入其他分校；總部可處理全分校資料。

## 總部仍須提供的業務資料

1. 115.07 起正式假日／統一公休清單，含日期、適用分校與是否計入 16 小時。
2. 每筆假日假抵扣或補課的實際時數與完成狀態。
3. 升學成果的學生名單、科目、證明文件、主任與總部審核結果、起訖月。
4. 年度績優的得獎年度與次年度適用起訖日；2026 年資料不追溯套用。
5. 扣除案件類型、事實說明、主任確認人／時間、總部核准人／時間與生效期間。

報表不會把上述資料缺漏當成 0 或直接判定不符合，會回傳「待人工確認」與缺少欄位。

## 主任需確認的規則衝突

1. 主任已定稿：假日假是「維持資格、不創造時數」。假日前常態排課滿16小時者，假日請假不扣假日倍率與週16段獎金；常態排課不足16小時者，假日假不能補成10%倍率。系統以常態假日排課基準計算，假日假只作為分開的說明與週獎金例外資料。
2. 主任已定稿：每日4小時低消後，常態排課5.5小時為0.75段；常態排課固定至22:00的課段，即使當日到21:30仍算完整段。補課與臨時加課不計入平日下午倍率。

## Alltrue 操作入口

主任從「正職薪資要件」頁面即可完成資料補登與審核，不需要直接操作資料庫：

1. 全校放假先在課程管理用「連假批次請假」處理堂次。薪資頁預設是幫老師補請假／補課。
   - **請假／補課抵扣**：幫某一位老師補登系統沒抓到的請假或補課。
   - **假日曆／官方活動／統一公休**：少用。只在薪資假日曆缺資料時補登，不是日常全校放假入口。
2. 填寫日期、證明／備註後送出；新資料會以「待審核」出現在右側。核准前可按「修改」或「撤回」。
3. 右側待辦清單可完成主任核准／確認；扣除案件再由總部執行第二階段核准。已核准資料才會進入薪資判定。
4. 報表項目的「待人工確認」會列出實際缺少欄位，補登並完成審核後按重新整理即可重新計算。

這個流程採用成熟 HR 系統常見的「表單補登 → 狀態化待辦 → 分級審核 → 審核後才納入計算」模式，避免把未知資料誤當成 0 或直接扣薪。
