## 2026-11-08 — fix(learning): 編輯視窗與手機卡片補上退回待審
<!-- release-notes: silent_ship=silent-2026-11-08-assessment-approval-rollback -->
<!-- silent-reason: 本次只交付 draft PR，尚未部署驗證；使用者明示不發通知，公告留待後續發布作業。 -->

- SourceRef: `alltrue:bug_report:523`；Refs #5114。依使用者已核對的匿名證據修復，未取得含個資附件、未讀寫正式資料。
- 根因：列表已有 `canRollbackApproval` / `rollbackApproval`，編輯 footer 與手機卡片未接上同一操作（F1 狀態／入口收尾缺漏；相關歷史 #1621、#3824、#4907）。不是新的角色或帳務政策。
- 三個入口共用 `useLearningRecordRollback`，沿用主任能力判斷、已核准限制、Bearer API 與後端校區／reviewer 守門；不修改後端 rollback、扣堂、出勤、薪資或授權語意。
- 取消與明確拒絕不更新資料；網路中斷、5xx、不可解析／錯誤形狀的 2xx 為 outcome unknown，可能已完成退回及 XP／扣堂撤銷。保留輸入並阻擋該筆重送與儲存；須先由「重新查詢狀態」透過既有 keyset API 精確讀取該 ID、更新審核欄位與 counts，才解除鎖定；查不到、回應無效或查詢失敗仍鎖定。
- 已確認成功的 POST 即使原視窗已關閉也會同步目前 scope 的列表與 counts。modal 的更新另以 ID＋查詢起點 epoch 守門，只套用審核欄位；重開同筆需安全查詢狀態，不可維持錯誤的 approved 預期。不同 modal 的正文不被覆蓋；舊列表／counts 回應以請求序號淘汰，避免蓋回 approved。token 尚未取得就失效的請求仍不送出。
- 防再犯：真實頁面元件測試覆蓋桌面／手機、主任／老師、已核准／待審、取消、成功、HTTP／網路失敗、未儲存輸入、重複與過期請求；本機 Playwright 合成頁驗證 footer 可操作。測試不使用正式帳號或資料。後續 review 另在全新 Docker PHP 8.2／MySQL 8 的 internal network 與 AllTrue_test 執行合成 PHPUnit；沒有 mount repo 的 .env、正式憑證或資料。
- 回滾邊界：revert 本 PR 的程式 commit 可恢復 UI；UI 回滾不撤銷主任日後透過既有 API 已成功執行的業務操作。
- 交付界線：#5120 維持 draft；review follow-up 僅保留本機 commit，未 push、未更新 PR、未 dispatch workflow。`ui-preview.yml` 的 opened／synchronize 會部署共用 preview 並寫 sticky comment，所以使用者已暫停遠端寫入；不得以 draft 規避此邊界，亦不修改 workflow 或 gate。正式路徑驗證與回報者驗收均未執行；不得宣稱已上線。

### Review follow-up：既存後端 stale route binding 診斷（未修復）

- `LearningRecordReviewerFromLoginTest::test_diagnostic_stale_bound_rollback_decrements_teacher_count_twice_but_reverses_usage_once` 是現況 characterization，不是正確產品行為的回歸契約；修後應改為拒絕 stale 第二次操作。沒有修改 controller、service、權限或 migration。
- 以兩份先讀為 approved 的 route-model snapshot，重放 A 完成後 B 才取得課程鎖的合法序列。實際 controller／MySQL SQL 順序：狀態判斷 → StudentClass `FOR UPDATE` → XP 撤銷 → LearningRecord UPDATE → User 教學次數減少 → approval sign-in／ledger 收尾；無鎖定重讀 LearningRecord。
- 合成結果：A、stale B 都回 200，TeachingSessionCount 2→1→0，另一筆 LR 仍 approved。此交錯下 sign-in 僅作廢一次、reverse ledger 一筆、UsedSessions／XP 在 B 不再降低；fresh 第二次 HTTP request 讀到 pending 則回 409。不可外推成所有重送必然錯誤或正式資料已損毀。
- 限制：這是 controller 層對預綁定模型的決定性序列重放，未執行兩個真實 HTTP process 同時等待鎖，也不是所有可能交錯／deadlock 的證明。前端每頁鎖與讀後確認不提供跨 client 的 exactly-once 保證；既存伺服器風險保留，修復另需授權。

- 完整本機調查、最小保護候選、測試矩陣與 rollback：[rollback concurrency audit](../audits/2026-11-08-5120-rollback-concurrency.md)。
