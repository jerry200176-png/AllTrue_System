## 2026-09-03 — feat(parent): Multi-Guardian canonical Portal cutover
<!-- release-notes: silent_ship=silent-2026-09-03-multi-guardian-cutover -->
- Portal LINE 授權在 flag 開啟且 Guardian 存在時僅走 active／read_only `student_guardians`；無 Guardian 列才暫用 SLB 相容。
- revoke 同步取消 verified SLB；手機登入比對任一 active guardian 手機；多子女切換改共享 guardian。
- 新增 `guardians:cutover-audit`／`repair_slb`；禁止以共用手機合併不同 LINE 身份。不 drop `parent_phone`。

## 2026-09-03 — release(parent): Multi-Guardian 正式版（Staff CRUD + Portal authZ + cutover）
<!-- release-notes: staff_update=staff-2026-09-03-multi-guardian-ga -->
- **正式版**：一位學生可有多位監護人；家長 Portal 以 `guardians` / `student_guardians` 為授權來源（active／read_only）；支援多子女與跨分校切換。
- Staff 可新增／解除監護人；**revoke 立即失效對應 ParentSession，並取消該 LINE 的 verified SLB**，legacy 路徑不可繞過。
- `parent_phone` 與 SLB 表保留作投影／相容；flag 關閉可 rollback 且仍可經 SLB／legacy 手機登入。
- Cutover：deploy 後先 `cutover_audit`，有 orphan 先 `repair_slb`，不可硬切。

## 2026-09-03 — fix(pop): 失敗 dry-run 支援受控重試並保留歷史 attempt
<!-- release-notes: silent_ship=silent-2026-09-03-pop-dry-run-retry -->
- 相同 request 的成功 dry-run 維持冪等 replay；失敗 dry-run 在 request、參數、catalog 與 context 未漂移且策略恢復後可追加 retry。
- 每次 retry 使用 append-only execution attempt identity，不改 caller idempotency key；execute／verify／rollback 的 mutation replay gate 維持不變。
- 補上 payload／catalog／production context drift fail-closed 與 mutation non-repeat regression tests；本次不執行任何 Huang repair。

## 2026-09-03 — feat(parent): Parent Portal multi-guardian dual-read authZ
<!-- release-notes: silent_ship=silent-2026-09-03-multi-guardian-portal-authz -->
- Parent Portal LINE login／切換學生在 `PERF_MULTI_GUARDIAN` 開啟時改走 `guardians` + `student_guardians`（active／read_only）dual-read；flag 關閉仍僅 verified SLB。
- Staff revoke 監護關係後立即失效對應 `ParentSession`；revoked 優先於殘留 SLB。不新建 ParentIdentity 表、不 cutover `parent_phone`。
- PB-00 對 PB-04 的過期硬阻擋已解除（Founder GO）；rollback = 關 flag。

## 2026-09-03 — feat(parent): 學生管理多家長 CRUD（flag 閘控）
<!-- release-notes: silent_ship=silent-2026-09-03-multi-guardian-staff-crud -->
- 主任可在學生編輯（`PERF_MULTI_GUARDIAN` 開啟時）新增／解除多位監護人並指定主要聯絡人。
- API 在 flag 關閉時回 404；**production flag enable 仍需 Founder GO**。預設畫面與 `parent_phone` 行為不變。

## 2026-09-03 — fix(parent): 多家長 LINE 綁定通知與偏好一致性
<!-- release-notes: silent_ship=silent-2026-09-03-multi-parent-line-notify -->
- 學費催繳改為推播給該學生所有已驗證 LINE bindings，不再只取第一筆。
- 家長通知偏好改為只更新目前登入的 LINE binding；同學生其他家長偏好不受影響。
- 若 session 帶 `line_user_id` 但該 binding 已撤銷，偏好更新 fail closed（422），不 fallback 到其他家長。
- 停止在 LINE 綁定時覆寫 legacy `Student.LineID`（canonical 為 `student_line_bindings`）。
- **Merge ≠ migrate**：合併至 main 不授權 production migration；`ParentSession.line_user_id` 欄位於 production 僅在 Founder activation GO 後套用。不新增第二家長手機欄、不做 ParentIdentity／GSR 架構擴張。

## 2026-09-03 — feat(parent): 多 Guardian 加法模型與雙寫雙讀（dark launch）
<!-- release-notes: silent_ship=silent-2026-09-03-multi-guardian-foundation -->
- 新增 `guardians` / `student_guardians`（Student 1:N），保留 `parent_phone`；dual-write 在表存在時啟用，dual-read 由 `PERF_MULTI_GUARDIAN`（預設關）控制。
- Dual-write 以 legacy `parent_phone`／`parent_name` 為唯一寫入來源，避免 flag 開啟後讀回 Guardian 舊值造成 stale sync。
- **Merge ≠ migrate**：合併至 main 不授權 production migration；production migrate／activation 僅在 Founder GO 後執行。不加 `parent_phone_2`、不做 big-bang cutover。

## 2026-09-02 — fix(billing): 課程查找同步按堂／按時計費單位
<!-- release-notes: staff_update=staff-2026-09-02-course-rate-unit-consistency -->
- 修正課程編輯由按堂切換為按時計費後，`rate_unit` 未完整送回與總費用仍按堂計算的問題。
- 課程查找現在依最新計費單位顯示「每堂／每小時」與正確總費用；按堂課程計價維持不變。
- 未新增資料庫欄位、不批次改寫既有付款資料；補上前後端 full-chain regression test。

## 2026-09-02 — fix(schedule): 移除固定時段不再誤判自己衝堂
<!-- release-notes: staff_update=staff-2026-09-02-fixed-slot-removal-and-teacher-workbench -->
- 固定課程由週三＋週四移除週四時，不會再把保留的週三既有堂次當成新增調課目標；只有真正新增或變更到新時段才做衝堂檢查。
- 老師工作台重整課表時保留上次成功資料，課程格可開啟對應課程詳情，週一至週日標題在手機與桌面捲動時維持可見；admin／director 頁面與權限路徑不變。
- 補上固定時段移除、真正衝堂、老師事件導向與工作台更新狀態回歸測試；production 驗證採唯讀 workflow，不直接修改個案資料。

## 2026-09-02 — fix(billing): 電子收據預計堂次文案恢復
<!-- release-notes: staff_update=staff-2026-09-02-receipt-expected-session-copy -->
- 電子收據的未實際上課、未取消堂次恢復顯示「（預計）」；已上課堂次與其他排課／點名狀態不變。
- 本次只調整收據畫面、複製文字與圖片輸出的文案，不改排課或後台狀態。

## 2026-09-01 — improved(ux): 新建與編輯課程共用老師空檔查詢
<!-- release-notes: staff_update=staff-2026-09-01-course-availability-planner -->
- 編輯既有課程時，現在可直接用與新建課程相同的老師空檔／容量試算，並將候選時段套用到固定排課欄位。
- 更換老師、開課日、固定星期或時段後，舊試算會失效並要求重新查詢；既有後端衝堂、固定課程、recurring、權限與儲存規則不變。

## 2026-09-01 — improved(ux): 新增排課可先找可行時段
<!-- release-notes: staff_update=staff-2026-09-01-scheduling-intersection-helper -->
- 新增課程時可輸入學生可配合的星期／時間窗口，核對已選老師的可服務分校、未來四次（至課程結束日）固定日期空檔與容量，點選候選時段即可套用。
- 試算沿用既有跨分校可用性資料與後端衝堂檢查；資料不完整時不提供候選，不寫回學生資料，也不改建立課程規則。

## 2026-09-01 — improved(ux): 行事曆次要工具需要時再展開
<!-- release-notes: staff_update=staff-2026-09-01-calendar-secondary-controls -->
- 月份、週次、跳至日期與日／週檢視維持直接可用；教室、老師／學生篩選與排課工具收進「篩選與更多操作」，需要時再展開。
- 展開後原有篩選、老師請假、教室管理與快速排課操作維持不變；收合時會顯示目前啟用的篩選數。

## 2026-09-01 — fix(calendar): 行事曆調課不再被不完整預判誤擋
<!-- release-notes: staff_update=staff-2026-09-01-calendar-reschedule-authority -->
- 行事曆調課的送出前提示改為提醒，不會因畫面尚未載入完整的請假／課堂資料而直接禁止確認。
- 確認後仍由後端做最後衝堂檢查；真正衝堂會保留錯誤提示且不會寫入變更。

## 2026-09-01 — improved(ux): Bug 回報提交後保留追蹤入口
<!-- release-notes: staff_update=staff-2026-09-01-bug-report-tracking -->
- Bug 回報送出成功後會保留回報編號與確認訊息，不再短暫顯示後自動消失。
- 可直接點選「查看回報進度」前往 Bug 回報頁；不改回報狀態流轉、留言權限、API 或資料。

## 2026-09-01 — fix(ops): Bug 詳情證據改為目標相符
<!-- release-notes: staff_update=staff-2026-09-01-bug-detail-target-correctness -->
- Bug 詳情 read-only dump 現在只會執行與該筆 Bug 明確對應的診斷 probe；未配置的 probe 會標示不適用，不再把固定歷史個案混進其他回報。
- 輸出新增目標 Bug、probe 適用性、產生時間、唯讀／去識別化與 decision-grade 欄位；需要目標證據但尚未配置時，workflow 會失敗並保留明確的未判定 artifact。

## 2026-09-01 — improved(ux): 老師首頁先看今天的課表
<!-- release-notes: staff_update=staff-2026-09-01-teacher-week-disclosure -->
- 老師首頁的本週課表預設只展開今天，其餘日期仍可點開查看，減少一進頁面就同時展開大量課堂。
- 保留跨分校課表、日期切換、課堂內容與既有評量／回報操作；本次只調整預設資訊呈現。

## 2026-09-01 — fix(course): 歷史課程顯示堂數待對帳
<!-- release-notes: staff_update=staff-2026-09-01-history-usage-balance-visibility -->
- 歷史課程卡現在也會直接顯示「堂數待對帳」與原因提示，不會因課程已結案／完課而藏起資料異常；不改堂數、帳務、出勤或扣堂資料。

## 2026-09-01 — fix(calendar): 調課預覽優先採用當日請假狀態
<!-- release-notes: staff_update=staff-2026-09-01-calendar-leave-precedence -->
- 修正請假狀態正在同步時，行事曆調課仍把老師誤判為滿段的問題；同日已請假的課程現在會正確釋放可用時段，仍保留對有效課程的衝堂檢查。

## 2026-09-01 — fix(billing): 未繳課程可結案但保留待對帳狀態
<!-- release-notes: staff_update=staff-2026-09-01-unpaid-settlement-reconciliation -->
- 未繳費課程現在可以結案並停止後續排課，不會再被「未繳費」前置條件卡住。
- 結案時若尚未完成收款，課程會明確標記「結案待對帳」，並留在帳務中心待處理；確認入帳後才轉為一般已結算。
- 未改寫既有付款資料，也不會把未繳費課程誤標成已繳費。

## 2026-08-31 — improved(ux): 課程查找明確顯示堂數待對帳
<!-- release-notes: staff_update=staff-2026-08-31-usage-balance-visibility -->
- 堂數扣堂與課堂狀態不一致時，課程名稱旁會直接顯示「堂數待對帳」，不再只藏在上課時段欄或多提醒摘要裡。
- 課程查找摘要新增待對帳筆數與原因提示；不改堂數、帳務、出勤或扣堂資料。

## 2026-08-31 — fix(schedule): 月結開課日跨固定星期仍建立首堂
<!-- release-notes: staff_update=staff-2026-08-31-monthly-opening-date -->
- 月結課程的開課日即使不在固定上課星期內，現在仍會建立並顯示為首堂；後續固定星期的排課維持原規則。
- 單課程與多科方案共用同一個後端排課契約，並補上前端預覽與前後端回歸測試。
- 不改付款、結算、production 資料、既有出勤或扣堂歷史。

## 2026-08-31 — fix(calendar): 調課預覽正確排除請假與取消課程
<!-- release-notes: staff_update=staff-2026-08-31-calendar-leave-capacity-preview -->
- 行事曆調課的送出前檢查現在會與課程查找一致，排除同日期已請假、已調整請假、核准請假與取消的課程，不再把實際空出的老師時段誤判為滿段。
- 真正有效的課程仍會被容量規則攔截；後端原子調課檢查維持為最後權威。

## 2026-08-31 — feat(payroll): 主任可查看每週16段課達標與課程構成
<!-- release-notes: staff_update=staff-2026-08-31-weekly-16-segments -->
- 正職薪資要件頁現在以有效點名的實際課程計算週一至週日段數：正課依課程時長換算、試聽每堂 1 段、輔導 0 段，並可展開查看構成課程。
- 每週總段數大於等於 16 段即標示達標；不要求已核准 LearningRecord，也不改變其他薪資審核或獎金規則。

## 2026-08-31 — fix(ops): Phase-A bug triage workflow accepts persisted replies
<!-- release-notes: staff_update=staff-2026-08-31-bug-triage-result-contract -->
- 修正 Bug 分診 workflow 將已成功寫入的公開回覆誤判為失敗；現在能正確辨識已保存的回覆與冪等略過結果，避免重跑造成誤判。
- 不改 Bug 狀態規則、回覆權限、帳務資料或產品行為。

## 2026-08-30 — improved(ux): 學生管理操作按鈕語意更穩定
<!-- release-notes: silent_ship=silent-2026-08-30-students-button-semantics -->
- 學生管理頁的新增、編輯、課程、身份關聯與視窗操作現在明確標示為一般按鈕，避免未來被表單情境誤當成送出。
- 不改學生、課程、帳務、身份資料、權限、API 或既有操作流程。

## 2026-08-30 — improved(ux): 出缺勤操作按鈕語意更穩定
<!-- release-notes: silent_ship=silent-2026-08-30-attendance-button-semantics -->
- 出缺勤頁的補卡、點名、查詢、修改與對話框操作現在明確標示為一般按鈕，避免未來被表單情境誤當成送出。
- 不改出缺勤狀態、資料、權限、API 或既有操作流程。

## 2026-08-30 — fix(ux): Bug 回報視窗不殘留上一筆提交提示
<!-- release-notes: silent_ship=silent-2026-08-30-bug-composer-success-reset -->
- Bug 回報成功後若立即關閉再重開，現在會回到乾淨的新回報視窗，不會誤顯示上一筆已提交訊息。
- 不改回報內容、附件、狀態流轉、留言權限、API 或資料。

## 2026-08-30 — improved(ux): 老師工作台聚焦今天與本週
<!-- release-notes: staff_update=staff-2026-08-30-teacher-home-single-surface -->
- 今天待辦集中在單一工作佇列，移除不會顯示的舊待辦、提示音與重複捷徑。
- 本週跨分校課表直接可見；既有資料、導頁、API、權限與生產啟用流程不變。

## 2026-08-29 — improved(ux): Bug 回報工作區頁籤更容易辨識

<!-- release-notes: silent_ship=silent-2026-08-29-bug-reports-tabs-a11y -->

- Bug 回報與家長回饋的工作區頁籤補上明確的鍵盤與螢幕閱讀器語意；狀態篩選也會讀出目前選取項目。
- 不改回報內容、狀態流轉、留言權限、API 或資料；本次只改善內部支援工作區的操作辨識度。

## 2026-08-29 — improved(ux): 學生管理視窗更容易辨識

<!-- release-notes: staff_update=staff-2026-08-29-students-modal-semantics -->

- 學生管理的新增／編輯學生、課程、帳單、加購、年級升級與跨分校身份視窗補上清楚的對話框與標題語意，鍵盤與螢幕閱讀器能辨識目前工作區。
- 不改學生、課程、帳務、身份關聯資料、權限或 API；本次只改善既有視窗的操作辨識度。

## 2026-08-29 — improved(ux): 老師工作台捷徑會帶入目前工作區

<!-- release-notes: staff_update=staff-2026-08-29-teacher-queue-focus -->

- 從「查看今日任務」進入老師工作佇列時，畫面會捲到工作區並把鍵盤焦點交給「今天要完成」，讓下一步更明確。
- 不改任務排序、點名／評量資料、導頁、API 或權限；本次只改善既有捷徑的焦點銜接。

## 2026-08-29 — improved(ux): 課程查找的學生分頁更適合鍵盤操作

<!-- release-notes: staff_update=staff-2026-08-29-course-tabs-keyboard -->

- 課程查找中每位學生的「課程資料／帳務資料」分頁現在可用左右鍵或上下鍵切換，焦點會跟著目前工作區移動。
- 不改帳務資料、載入流程、課程操作、權限或 API；本次只改善既有分頁的鍵盤操作與辨識度。

## 2026-08-29 — fix(ops): 堂次轉移 reason 過長改為明確擋下

<!-- release-notes: silent_ship=silent-2026-08-29-entitlement-reason-length -->

- `repair:transfer-session-entitlement` 與轉移服務在寫入前檢查 `reason`／`decision_reference`／`actor` 不可超過 128 字，避免 DB 截斷錯誤導致整筆交易失敗且訊息難讀。
- 不改轉移語意、堂數計算、帳單或 production 啟用流程；過長輸入只回明確錯誤、不寫入。

## 2026-08-29 — fix(schedule): 不再建立沒有原堂次的調課目標

<!-- release-notes: staff_update=staff-2026-08-29-schedule-orphan-prevention -->

- 舊版跨日調課若找不到原日期的有效課堂紀錄，現在會在寫入目標排程前清楚拒絕，不會留下日曆無法操作的孤兒排程。
- 保留已有原堂次的合法跨日調課流程；未執行任何既有資料修復或生產啟用。

## 2026-08-29 — fix(schedule): 重複補排目標改回可理解錯誤

<!-- release-notes: staff_update=staff-2026-08-29-reflow-duplicate-target -->

- 固定排課重整若產生兩筆相同日期／時段的目標，會在任何堂次移動前回傳可理解的時段衝突，不再讓資料庫唯一索引錯誤直接變成 500。
- 保留原子交易與唯一索引防線；不改既有堂次、扣堂、評量或排課資料。

## 2026-08-29 — improved(ux): 工作台不被單一回覆來源卡住

<!-- release-notes: staff_update=staff-2026-08-29-teacher-partial-queue-error -->

- 家長回覆資料暫時失敗時，老師仍可繼續處理已載入的點名／評量待辦，不會整個工作佇列誤顯示為待確認。
- 回覆資料未完成時會明確提示部分待辦尚未載入；若關鍵的點名／評量資料失敗，仍維持防止誤判完成的完整錯誤狀態。
- 不改任務排序、導頁、資料、API 或權限；只修正工作台的部分失敗呈現。

## 2026-08-29 — fix(ops): 錯誤處理路徑恢復正常記錄

<!-- release-notes: staff_update=staff-2026-08-29-logging-facade-runtime -->

- 修正公開分校清單與排課／薪資例外處理中的 Laravel 記錄器呼叫；發生錯誤時會保留原本的 fallback 或錯誤回應，不會因記錄器名稱錯誤再觸發第二個 500。
- 未改資料、帳務、排課、權限或部署啟用流程。

## 2026-08-29 — improved(ux): 老師今日佇列只保留一顆主行動

<!-- release-notes: staff_update=staff-2026-08-29-teacher-secondary-cta -->

- 「現在先做」維持實心主按鈕；「接著處理」改為次要按鈕，避免同一區塊多顆橘黃主行動搶注意力。
- 不改任務排序、導頁、點名／評量資料、API 或權限；手機版次要行動仍維持滿寬可點。

## 2026-08-29 — fixed(schedule): 補課候選先排除同學生跨合約衝堂

<!-- release-notes: staff_update=staff-2026-08-29-cross-contract-makeup-conflict -->

- 家長請假案件搜尋補課時段時，現在會同時檢查同一學生其他合約已物化的正式堂次，以及尚未物化但已預約的排課，避免先顯示不可能成立的候選。
- 確認補課時仍由後端 ClassSession 寫入防線做最後一次衝堂檢查；若搜尋後被其他操作占用，回傳可理解的衝堂錯誤且整筆交易回滾。
- 不改付款金額、既有出席／扣堂歷史、合約日期或試聽／平行課程語意。

## 2026-08-29 — improved(ux): 評量審核佇列分頁語意更清楚

<!-- release-notes: staff_update=staff-2026-08-29-learning-review-tabs-a11y -->

- 主任「待核准／需修改／已核准／已退回／全部」與老師「全部／待審核／需修改／已核准」分頁改為明確的 tab 語意，並連到評量清單工作區。
- 切換後鍵盤與螢幕閱讀器能辨識目前佇列；不改審核規則、核准同步點名／扣堂、API、權限或既有批次操作。

## 2026-08-29 — fix: 新增課程「去加購」不再沒反應

<!-- release-notes: staff_update=staff-2026-08-29-goto-purchase -->

- 學生管理點「新增課程」後，若學生已有進行中課程，再點「去加購」會打開該課的加購堂數視窗。
- 先前衝突視窗只認 `existing_course_id`，但新增課程預檢回傳的是 `id`，對不到課程就靜默關閉。
- 找不到課程時會提示重新整理，不再沒有畫面反應。

## 2026-08-29 — fixed(ux): 登入後點側欄不再被拉回首頁

<!-- release-notes: staff_update=staff-2026-08-29-profile-nav-clobber -->

- 登入後若 `/me` 個人資料稍晚才回來，側欄切到「我的課表」「課程查找」等頁面時，不會再被強制拉回教學工作台／主任總覽。
- 個人資料重新整理仍會處理強制改密與角色首頁冷啟動；不改權限、分校、API 或各頁業務邏輯。
- UI smoke 側欄導航改為限定側欄並短重試，降低把此競態誤判成按鈕失效。

## 2026-08-29 — improved(ux): 學生課程下一步更明確

<!-- release-notes: staff_update=staff-2026-08-29-student-course-next-action -->

- 學生課程工作區會用「現在先處理」清楚說明續報、付款待確認、資料待補與一般課程的下一步，主任不必只靠顏色或猜按鈕用途。
- 主行動會依現有課程狀態導向續報加購、繳費資訊或編輯課程，並保留原有 handler；卡片提醒狀態與文字保持一致。
- 不改課程資料、付款、排課、權限或 API 行為；手機版主行動維持滿寬可讀與鍵盤可操作。

## 2026-08-29 — improved(ux): 主任收件匣工作區更穩定

<!-- release-notes: staff_update=staff-2026-08-29-notifications-dialog-a11y -->

- 主任收件匣的「待辦案件／營運通知」分頁現在會清楚連到目前內容區，切換後只聚焦正在處理的工作區。
- 核帳登記改用共用對話框，支援一致的關閉按鈕、Escape、初始鍵盤焦點與捲動鎖定；通知操作也不會意外觸發表單送出。
- 不改付款資料、核帳規則、API、權限或既有導頁行為；這次只改善介面語意與操作穩定性。

## 2026-08-29 — improved(ux): 老師工作台控制項更穩定

<!-- release-notes: staff_update=staff-2026-08-29-teacher-card-a11y -->

- 今日打卡狀態卡片改用原生按鈕，保留原有導向 attendance 行為，並補上狀態標籤、即時狀態宣告與清楚的鍵盤焦點。
- 本週課表的上一週／下一週，以及課表中的圖示操作，補上明確的按鈕類型與可讀名稱，避免只看圖示或 hover 提示才能理解。
- 不改出缺勤、課表、評量資料、API、權限或既有導頁行為；這次只改善控制項語意與操作穩定性。

## 2026-08-29 — improved(ux): 出缺勤工作區的切換與狀態更容易理解

<!-- release-notes: staff_update=staff-2026-08-29-attendance-tab-status-a11y -->

- 主任的「學生點名／老師打卡」分頁現在會明確連到目前內容區，切換後螢幕閱讀器與鍵盤使用者都能辨識工作區。
- 待點名堂次的到班狀態按鈕補上可讀的選取狀態與鍵盤焦點提示，不再只依賴顏色或視覺 active 樣式。
- 不改點名資料、扣堂、RFID、API、權限或既有送出行為；老師模式維持原有單一學生點名工作區。

## 2026-08-29 — improved(ux): 老師狀態與識別資訊更清楚

<!-- release-notes: staff_update=staff-2026-08-29-teachers-list-status -->

- 老師管理的「正式老師／待審核／停用」分頁現在會明確連到目前內容區，切換後只聚焦該狀態的老師。
- 待審核與停用數字改用對應的提醒色，和老師卡片上的在職／待審核／停用標籤一致，降低把一般狀態誤判成危險警示的機會。
- RFID 識別碼改用較穩定、低噪音的等寬數字呈現；不改老師資料、帳號、RFID 綁定、權限或既有操作行為。

## 2026-08-29 — fixed(billing,ux): 帳務分頁只顯示目前工作區

<!-- release-notes: staff_update=staff-2026-08-29-billing-tab-panels -->

- 帳務中心的「待處理／已結清課程彙總／收據紀錄」現在只會顯示目前選定的內容區，避免切到已結清時同時看到收據工作區。
- 三個分頁補上清楚的鍵盤與螢幕閱讀器控制關係，讓主任能更快判斷目前正在處理哪一種帳務。
- 不改付款規則、收據資料、API 或權限行為；手機版維持原有可讀布局。

## 2026-08-29 — improved(ux): 學生列表展開更容易操作

<!-- release-notes: staff_update=staff-2026-08-29-students-row-disclosure -->

- 學生列表的資料列現在可用 Enter 或 Space 展開／收合，並明確連到下方課程工作區。
- 勾選、編輯與刪除等既有操作維持獨立，不會因資料列取得鍵盤焦點而誤觸展開。
- 保留原有學生、課程、付款、權限與導頁行為，手機版維持既有可讀寬度。

## 2026-08-29 — improved(ux): 課程查找操作層級更清楚

<!-- release-notes: staff_update=staff-2026-08-29-course-management-hierarchy -->

- 課程查找的學生群組改用清楚分離的展開按鈕與「專注此學生」操作，避免把兩個不同動作混在同一個可點區域。
- 課程資料、帳務資料與歷史課程的展開狀態補上完整的鍵盤／螢幕閱讀器關係；原有課程、帳務、排課與權限行為不變。
- 延續目前的淺色、navy、暖橘設計語言，手機版維持單欄與可讀寬度。

## 2026-08-29 — improved(ux): 老師工作台先做一件事

<!-- release-notes: staff_update=staff-2026-08-29-teacher-next-action -->

- 老師工作台會把排序後的第一個待辦獨立標示為「現在先做」，直接提供對應的行動按鈕，減少第一眼判斷負擔。
- 其餘待辦仍保留在「接著處理」清單；請假待審堂次、原有資料、導頁與權限行為不變。
- 手機版主行動會在同一張卡片內完整堆疊，維持可讀寬度與鍵盤／螢幕閱讀器可操作性。

## 2026-08-29 — improved(ux): 學生課程細節分層更清楚

<!-- release-notes: staff_update=staff-2026-08-29-student-course-disclosure -->

- 學生課程頁將「目前課程工作區」與歷史課程明確分層，主任先看選定課程的完整資料，再按需展開歷史內容。
- 歷史課程保留原有編輯與刪除操作，並補上展開狀態與控制關係，讓鍵盤與螢幕閱讀器能正確理解。
- 不改課程資料、付款、排課、權限或既有操作 handler；手機版維持單欄可讀布局。

## 2026-08-29 — improved(ux): 學生課程總覽更容易判斷下一步

<!-- release-notes: staff_update=staff-2026-08-29-student-course-overview -->

- 展開學生後先看到課程總覽，摘要顯示進行中、需要注意與歷史課程數量，減少在多門課程間反覆尋找。
- 課程選擇器會優先帶出需要處理的課程，並保留課程進度、付款、排課與課程操作；切換課程不改變既有資料與權限行為。
- 手機版將主要課程工作區維持在可讀寬度，學生列表的次要欄位仍可水平查閱。

## 2026-08-28 — improved(ux): 學生課程資訊更清楚

<!-- release-notes: staff_update=staff-2026-08-28-student-course-summary -->

- 學生主檔的進行中課程改以摘要卡呈現，先顯示課程進度、老師、時段、地點、費用與付款狀態，閱讀順序更清楚。
- 堂數制會顯示剩餘／總堂數與可讀的進度；月結課程改顯示結算週期，資料不足時不會產生誤導性的百分比。
- 續報加購保留為低堂數課程的主要下一步，其餘付款、帳單、繳費資訊、編輯、結案與刪除集中在「更多操作」。

## 2026-08-28 — improved(ux): 側欄常用功能更聚焦

<!-- release-notes: staff_update=staff-2026-08-28-sidebar-focus -->

- 主任側欄保留今日工作、教學現場、學生與課程、財務與人事四個高頻工作區，進入系統後更容易找到每天要處理的事。
- 報表、進階教學工具、訊息回報與設定仍完整保留，集中到「更多功能」面板；目前頁面、徽章與權限行為不變。
- 「更多功能」支援明確的 active 狀態、Escape 關閉、收合側欄與鍵盤操作，手機版維持原本的 More 抽屜。

## 2026-08-28 — improved(ux): 評量審核佇列分工更清楚

<!-- release-notes: staff_update=staff-2026-08-28-learning-review-queues -->

- 主任的學習評量頁將「待主任核准」與「老師需修改」拆成兩個工作佇列，避免把不同下一步混在同一個待審清單。
- 分頁徽章、伺服器端狀態篩選、隱藏筆數提示與空狀態同步對齊；頁面仍保留全部、已核准與已退回查閱。
- 佇列下方直接顯示目前工作目的，主任可依提示核准、追蹤修改或查閱，不改變評量資料與權限規則。

## 2026-08-28 — improved(ux): in-app 問題回報更穩定

<!-- release-notes: staff_update=staff-2026-08-28-in-app-bug-report -->

- Bug 回報視窗改用共用對話框，支援 Esc 關閉、手機底部抽屜、背景捲動鎖定與一致的關閉按鈕；貼圖、拖曳、選檔與送出流程保留。
- Bug 詳情的狀態更新、留言、可見性與回報者驗收失敗時，改在原位置顯示可關閉的錯誤提示，不再用瀏覽器 alert 打斷操作。
- UI／營運改善清單不再作為產品頁面提供；工程規劃改由 GitHub Issue／PR／設計文件追蹤，產品側只保留實際業務功能。

## 2026-08-28 — improved(ux): 出缺勤工作區先處理異常

<!-- release-notes: staff_update=staff-2026-08-28-attendance-workspace-focus -->

- 主任進入出缺勤管理時，學生點名與老師打卡分成清楚的工作分頁；學生點名先顯示待點名堂次，老師打卡先顯示需要補卡的課表異常。
- 到班摘要、行政出勤、系統待比對、完整打卡紀錄與匯出功能仍保留，但改為按需展開，降低主任第一眼的資訊負擔。
- 老師工作台的開始點名、補點名與補建課堂流程不變；本次只調整頁面層級與響應式呈現。

## 2026-08-28 — improved(ux): 排課操作提供安全復原

<!-- release-notes: staff_update=staff-2026-08-28-schedule-safe-recovery -->

- 主任取消單堂課後，重新開啟該堂會看到「復原上一個變更」；系統只在有可驗證的最近操作、且沒有新衝堂時允許復原。
- 復原要求原因並以交易同步排課狀態、必要的評量／點名與堂數，所有操作保留稽核紀錄。

## 2026-08-27 — fix(attendance,billing): 堂次轉移同步扣堂台帳

<!-- release-notes: staff_update=staff-2026-08-27-transfer-ledger-reconciliation -->

- 已上課堂次轉移到新合約時，現在會同步點名、評量、扣堂台帳與衍生堂數；另提供受控對帳流程修復既有轉移造成的台帳漂移。

## 2026-08-27 — fix(attendance,ux): 取消堂次清理評量並隱藏零差異

<!-- release-notes: staff_update=staff-2026-08-27-session-evaluation-integrity -->

- 取消、請假、停課堂次不再留下待填評量；只有已上課、完成或遲到等有效出席堂次才列入評量與填寫率。
- 夜間堂數對帳只顯示仍有數字差異的課程；已經是 0 的列不再顯示為待處理。
- 主任總覽將老師評量填寫率移到明顯位置，直接顯示分校整體填寫率、待填堂數、需要跟進的老師與每位老師的下一步狀態。

## 2026-08-27 — improved(ux): 課程查找同頁編輯與新增堂次

<!-- release-notes: staff_update=staff-2026-08-27-course-management-inline-scheduling -->

- 課程查找的「編輯」改為在原頁開啟課程編輯視窗；月結課程可直接設定結束日，不再跳到學生管理後再返回。
- 月結課程設定有效日期區間後，可在同一頁使用「排月結／新增月結堂次」建立指定日期與時間的堂次；後端仍檢查日期有效性、老師／教室與學生衝堂。

## 2026-08-27 — fix(billing,course): 已繳費課程可直接結案且月結排課可回饋

<!-- release-notes: staff_update=staff-2026-08-27-paid-course-settlement -->

- 課程管理、學生管理與帳務提醒都提供「結案（不續報）」；已繳費的堂數制／月結制不必先走續報流程，仍保留付款與已上課紀錄。
- 堂數制仍會在確認視窗列出未使用堂數，只有主任明確確認放棄餘額時才取消未來排課；結案請求統一使用 `reason=settled` 語意。
- 月結「排月結」統一使用正規化課程識別碼；資料不完整或後端檢查失敗時，視窗會明確顯示原因，不再無聲無息。
## 2026-08-27 — improved(billing): 課程管理可直接開繳費通知

<!-- release-notes: staff_update=staff-2026-08-27-course-payment-slip -->

- 課程管理的帳務資料與「更多」選單，對未繳、部分繳及待對帳課程提供「繳費通知」入口，可直接預覽並複製通知給家長。
- 入口只讀取既有通知單資料，不新增付款、核帳或收據寫入；已繳費課程不顯示此操作。

## 2026-08-26 — improved(ux): 待辦直接定位帳務與課表

<!-- release-notes: staff_update=staff-2026-08-26-director-contextual-actions -->

- 通知中心的帳務與排課／代課通知會沿用既有學生、課程與日期脈絡，進入目標頁面後直接定位並高亮對應資料，主任不必再重新搜尋。
- 帳務仍依未繳費／續課分類處理；找不到目前分校資料時會顯示提示，不跨分校猜測、不自動寫入，付款、堂次、權限與後端 API 不變。

## 2026-08-26 — improved(ux): 課程編輯集中到學生主檔

<!-- release-notes: staff_update=staff-2026-08-26-course-edit-master-record -->

- 從課程管理編輯課程時，現在會帶到學生管理的同一位學生與同一門課，直接開啟學生主檔編輯表單，避免同一筆課程需要記住兩套編輯入口。
- 只接受目前分校清單中可驗證的學生與課程脈絡；本次不改付款、堂數、出缺勤、後端 API、資料庫或權限規則。

## 2026-08-26 — improved(ux): 課程管理導頁保留學生上下文

<!-- release-notes: staff_update=staff-2026-08-26-course-student-focus -->

- 從課程管理的學生群組進入學生管理時，會直接定位並展開同一位學生，主任不必重新搜尋；一般「前往學生管理」入口仍維持不指定學生的通用入口。
- 定位只接受目前分校學生清單中的合法學生，查無資料時不會跨分校開啟；本次不改付款、堂數、出缺勤、後端 API 或權限規則。

## 2026-08-26 — improved(ux): 課程建立回到學生管理主檔

<!-- release-notes: staff_update=staff-2026-08-26-course-create-entry -->

- 課程管理展開學生資料時，新增課程現在會導向「學生管理」主檔處理，避免同一筆學生／合約在課程管理另開建立流程。
- 課程查找、排課、調課與換師複製維持原流程；本次不改變資料、堂數、付款或權限規則。

## 2026-08-26 — improved(ux): 課程管理移除重複排課入口

<!-- release-notes: staff_update=staff-2026-08-26-course-action-dedup -->

- 課程管理每列的「排課／新增下一堂」現在只保留一個主要入口；「補課／補登」仍集中在「更多」選單，減少主任誤判操作差異。
- 本次只整理操作入口，不改變排課、堂數、付款、資料或權限規則。

## 2026-08-26 — improved(billing): 批次帳務送出前先看摘要

<!-- release-notes: staff_update=staff-2026-08-26-billing-batch-preview -->

- 帳務中心批次回報與批次確認入帳，現在會先顯示選取筆數、金額、付款方式與逐筆課程摘要；取消或返回修改都不會送出資料。
- 回報仍先是「待對帳」，確認入帳才會走正式入帳與收據流程；既有付款狀態、權限與 API 不變。

## 2026-08-26 — improved(ux): 報帳與排課流程加入無個資使用量測

<!-- release-notes: staff_update=staff-2026-08-26-ops-workflow-telemetry -->

- 帳務回報／確認入帳與新增排課／調課會記錄流程開始、完成、返回、錯誤類型與耗時，供後續刪減不必要步驟。
- telemetry 僅使用固定流程欄位，並沿用後端個資過濾；不記錄姓名、學號、課程 ID、金額、備註、電話或錯誤原文，且紀錄失敗不會阻塞操作。

## 2026-08-26 — fix(attendance): 過期堂次不再出現在出缺勤清單

<!-- release-notes: staff_update=staff-2026-08-26-attendance-session-trust -->

- 出缺勤作業清單現在以 `ClassSession` 堂次狀態為準；已取消堂次或點名與堂次合約不一致的殘留資料不再被當成有效出勤顯示。
- 自修與既有無堂次編號的臨時點名仍保留；本次不改扣堂、付款、權限或正式資料。

## 2026-08-26 — improved(billing): 帳務中心先顯示待處理佇列

<!-- release-notes: staff_update=staff-2026-08-26-billing-action-queue -->

- 帳務中心預設先顯示未繳費、部分付款與待對帳，主任可以直接開始今天要處理的工作；完整提醒、逾期與續課分類仍可切換查看。
- 在待處理中同時勾選不同狀態時，系統會提示分開處理，不會把回報與確認送到錯誤流程；已回報仍不等於已入帳。

## 2026-08-26 — improved(ux): 調課前先預覽衝堂

<!-- release-notes: staff_update=staff-2026-08-26-reschedule-preflight -->

- 主任在調課視窗選擇新日期與時間後，會先看到目前課表是否可能衝堂；已知達到老師或班型上限時，系統會停用確認並提示改選方向。
- 這是唯讀預覽，最後仍由後端重新檢查權限、房間、跨分校與原子調課規則；付款、堂次與排課資料模型不變。

## 2026-08-26 — improved(ux): 主任待辦可返回工作台

<!-- release-notes: staff_update=staff-2026-08-26-dashboard-return-context -->

- 從主任今日工作進入帳務、點名、評量或課表處理後，頂端會保留「回到主任今日工作」入口，完成一筆工作後不必重新尋找待辦。
- 一般側欄切頁會清除暫時脈絡；不改變既有資料、權限、分校或帳務／排課寫入規則。

## 2026-08-26 — improved(ux): 統一側欄與手機導覽入口

<!-- release-notes: staff_update=staff-2026-08-26-navigation-registry -->

- 桌面側欄、收合側欄、手機底部導覽與 More 選單改由同一份角色導覽定義產生，避免入口漏改或角色看到不該有的頁面。
- 保留既有 page key、分校切換、badge 與後端權限邊界；側欄群組新增 `aria-expanded`，目前頁面維持 `aria-current`。

## 2026-08-26 — improved(ux): 主任常用營運流程一站開始

<!-- release-notes: staff_update=staff-2026-08-26-ops-workflow-quick-start -->

- 主任首頁新增「收款與核帳」、「新增排課」、「調課／代課」三個常用流程入口；帳務中心與班級行事曆也會提示下一步。
- 入口只簡化尋路，不改變既有權限、衝堂檢查、繳費回報與主任確認入帳規則。

## 2026-08-26 — fix(billing): 長備註確認入帳不再失敗

<!-- release-notes: silent_ship=silent-2026-08-26-payment-report-long-note -->

- 主任確認入帳時，較長的繳費說明會完整保留，不會因歷史欄位容量不足而讓整筆核銷失敗。
- 同一筆回報重試時會維持單一收款紀錄；付款狀態、金額與既有核帳流程不變。

## 2026-08-26 — improved(billing): 主任可從提醒直接開繳費通知與明細

<!-- release-notes: staff_update=staff-2026-08-26-payment-shortcuts -->

- 主任總覽的繳費提醒新增「繳費通知」與「繳費明細」入口，可直接預覽既有通知單、查看帳單／收款／收據時間線，再複製通知給家長。
- 帳務中心各列表的「對帳」入口改以「繳費明細」呈現，付款狀態、金額計算與既有核帳流程不變。

## 2026-08-26 — improved(billing): 課程卡直接顯示最近繳費備註

<!-- release-notes: staff_update=staff-2026-08-26-course-payment-summary -->

- 課程管理的學生課程卡會直接顯示最近一次繳費回報的日期、金額、備註與（主任可見的）匯款後五碼，不必再切到帳務中心重查。
- 摘要為唯讀資料；付款狀態、金額計算、對帳與收據流程不變。待對帳回報會明確標示「待對帳」。

## 2026-08-26 — improved(ux): Bug 回報可直接貼上截圖

<!-- release-notes: staff_update=staff-2026-08-26-bug-report-image-paste -->

- Bug 回報視窗支援直接貼上剪貼簿截圖、拖曳圖片或點擊選檔；三種方式共用圖片格式、5MB／5 張上限與即時預覽。
- 純文字貼上仍照常輸入描述；移除附件或關閉視窗會清理預覽資源，既有多段表單與後端附件 API 不變。

## 2026-08-26 — fix(attendance): 待點名列表遇到歷史衝突仍可載入

<!-- release-notes: silent_ship=silent-2026-08-26-read-side-session-conflict -->

- 讀取分校待點名堂次時，單一歷史重疊衝突不再讓整份列表失敗；衝突堂次維持不強制建立，並留下不含個資的伺服器診斷紀錄。
- 正式建立或調整堂次的學生重疊防護維持不變，並補上主任／分校讀取回歸測試。

## 2026-08-25 — fix(course): 有歷史證據的取消堂次可受控恢復並移轉

<!-- release-notes: staff_update=staff-2026-08-25-cancelled-session-recovery-transfer -->

- 課程管理的「合約／堂次調整」會辨識仍保留評量或點名紀錄的已取消堂次，明確標示為「已取消（可恢復）」；真正沒有歷史證據的取消堂次仍不可移轉。
- 恢復流程要求主任填寫原因，並在單一交易內恢復為已上課、同步移轉評量／點名／扣堂台帳及來源／目標合約餘額；留下最小化稽核紀錄。

## 2026-08-25 — fix(course): 堂次轉移先檢查目標時段衝突

<!-- release-notes: staff_update=staff-2026-08-25-transfer-slot-conflict-preflight -->

- 轉移堂次前先檢查目標課程是否已有相同日期／時段；衝突時回傳可理解的日期與處理方向，不再只顯示通用的 `Server Error`。
- 衝突會在任何來源堂次被修改前拒絕，避免重複堂次或已存在的評量／點名／扣堂資料被誤搬動；請先處理目標課程的重複堂次，再重新操作。

## 2026-08-23 — feat(billing): 未收款課程合約拆分精靈

<!-- release-notes: staff_update=staff-2026-08-23-split-contract-wizard -->

- 主任可在未收款、按堂且非共用課程中，選取已使用堂次，預覽並送出合約拆分。
- 後端以單一交易先轉移已使用堂次，再建立新的未收款合約並更正原合約堂數／金額；兩邊的堂次、帳務與餘額不平衡時整批拒絕。
- 已收款、待對帳、月結、方案課程與未使用的預排堂次維持原有鎖定規則；新流程只開放主任／超級管理員並留下最小化稽核紀錄。

## 2026-08-24 — improved(course): 編輯受限時直接導向下一步

<!-- release-notes: staff_update=staff-2026-08-24-course-editability-guidance -->

- 共用方案堂數受保護時，編輯視窗可直接開啟「設定方案總堂數」，並預填目前總堂數。
- 堂次／扣堂對帳與需要另開新課程的情境，提供前往既有審核或學生管理入口；不新增或繞過任何安全流程。

## 2026-08-24 — improved(course): 收斂主任課程操作入口

<!-- release-notes: staff_update=staff-2026-08-24-course-action-hierarchy -->

- 課程列第一層只保留編輯、排課與查看詳情，主任可先處理最常見工作。
- 帳單、合約／堂次調整、補課、換師複製與狀態管理移到「更多」並按情境分組；既有安全流程與功能不變。

## 2026-08-23 — improved(performance): 主任次要資料查詢優化

<!-- release-notes: staff_update=staff-2026-08-23-performance-backend-query -->

- 主任頁面的老師評量填寫率查詢改用可使用索引的日期／時段比對，減少資料量增加後的等待時間。
- 主任營運摘要合併重複的統計查詢，在不改變數字口徑的前提下降低資料庫往返次數。

## 2026-08-23 — improved(performance): 第一批頁面載入優化

<!-- release-notes: staff_update=staff-2026-08-23-performance-first-batch -->

- 主任首頁的獨立資料區塊改為平行載入；單一區塊失敗時，其他區塊仍可完成顯示。
- 登入初始化與行事曆資料請求減少等待鏈；Vite 內容雜湊資產改採長效快取，HTML 與版本資訊維持即時 revalidate。

## 2026-08-23 — fix(schedule): 寫入時阻擋學生重疊課程並對齊堂數摘要

<!-- release-notes: staff_update=staff-2026-08-23-overlap-entitlement-root-guard -->

- 主任新增／調整的未來 `ClassSession` 寫入會檢查同一學生的既有課堂與尚未物化的排課；同課程重送仍維持冪等，試聽課程維持既有旁聽例外。同一共用方案成員可維持刻意的平行科目軌；獨立平行課只有通過既有強制建立審計原因才放行，月結續約會先結束舊期再生成新期。歷史資料與讀側投影仍交由既有重疊稽核處理。
- 課程管理展開摘要的「已上」數字改採後端已用／剩餘堂數口徑，與購買堂數及剩餘堂數一致；共用方案仍以方案池顯示。

## 2026-08-24 — fix(course): 編輯前預檢與阻擋原因導引

<!-- release-notes: staff_update=staff-2026-08-24-course-editability-preflight -->

- 開啟課程編輯時先檢查扣堂、付款、共用方案與對帳狀態；被鎖欄位會直接標示原因與下一步。
- 一般編輯儲存競態失敗時保留後端錯誤分類，直接導向堂數更正、作廢帳單、方案調整或對帳流程。
- `student_class.edit_blocked` 以匿名稽核事件記錄主要阻擋原因與 HTTP 狀態，供後續統計主任反覆遇到的情境。

## 2026-08-23 — improved(course): 收斂主任的合約與堂次調整入口

<!-- release-notes: staff_update=staff-2026-08-23-director-adjustment-entry -->

- 課程管理的「操作」選單改用單一「合約／堂次調整」入口；未付款堂數更正與已上課紀錄轉移仍使用原本各自的安全流程，不新增重複 API。
- 未付款按堂課程會先讓主任選擇「堂數改少」或「轉移已上課紀錄」；其他課程直接進入可用的堂次紀錄轉移流程。

## 2026-08-23 — fix(course): 跨合約重複堂次同步沖回扣堂

<!-- release-notes: staff_update=staff-2026-08-23-duplicate-usage-reconciliation -->

- 重複課程審核取消非保留堂次時，會同步作廢該側的簽到／評量、沖回扣堂 ledger，並重算合約剩餘堂數；不再只改課堂狀態留下帳務殘留。
- 即使重複堂次先前已被標成取消，只要仍有扣堂證據，也會重新出現在審核清單，選定保留合約後可完成清理。
- 課程管理若發現課堂狀態、簽到觀察值與扣堂紀錄不一致，會顯示「堂數待對帳」，避免主任把矛盾數字直接當成收費依據。

## 2026-08-23 — fix(course): 合約更正與堂次轉移錯誤提示

<!-- release-notes: staff_update=staff-2026-08-23-contract-correction-transfer-safety -->

- 課程編輯儲存失敗改在編輯視窗頂端顯示，不再被底層頁面或 toast 遮住，並保留後端欄位錯誤細節。
- 尚未收款且原本 5 堂、實際只上 4 堂的按堂課程，請從「操作 → 更正未收款堂數」改為 4 堂；既有已上課紀錄保留，超出的未上排程取消並留下稽核紀錄。
- 堂次轉移只允許同一學生、同一科目且已上課（含遲到／完成）的堂次；未上課、請假、缺席或不相容目標課程會被拒絕。

## 2026-08-22 — feat(schedule): 一般合約新增「排課」按鈕（GitHub #1956）

<!-- release-notes: staff_update=staff-2026-08-22-manual-session-booking -->

- 一般（非進階模式）課程合約，課程管理頁面現在有「排課」按鈕，可直接為既有合約新增指定日期時段的課堂，不必再誤用「補課」功能。
- 沿用既有的排課檢查與建立流程，會先確認師資／教室無衝堂才建立，失敗原因會顯示在對話框內。
- 不影響已存在的自動週期排課、補課或調課行為。

## 2026-08-22 — incident(ops): #1387 production DB credential mismatch recovered

<!-- release-notes: silent_ship=silent-2026-08-22-db-credential-rotation-incident -->

- 正式站 DB authentication mismatch 已恢復；實際 MySQL grant row 是 `admin@%`，不是 `admin@localhost`。修復後 fresh TCP DB read 與 `/api/v1/branches` 回 HTTP 200。
- 沒有改資料表資料、權限或對話／文件中的密碼；舊式 in-place rotation 已停用，後續只能走具備 rollback、fresh Laravel DB read 與 DB-dependent smoke 的 staged rotation。
- **詳見**：`docs/AI_REGRESSION_LESSONS.md` R120、`docs/incidents/1387-staged-rotation-runbook.md`。

## 2026-08-21 — ops(branch): 停用敦化分校選單

<!-- release-notes: staff_update=staff-2026-08-21-dunhua-campus-retired -->

- 敦化分校保留歷史資料，但已從公開分校清單與前端離線後備清單移除。
- 不刪除學生、課程、出勤、帳務、評量或稽核紀錄；若要恢復，需由超級管理員明確重新啟用。

## 2026-08-21 — feat(assessment): 家長端檢測進度與補強狀態

<!-- release-notes: staff_update=staff-2026-08-21-parent-assessment-progress -->

- 家長端現在會顯示已完成複核的檢測分數、達標／再練習提示與補強進度。
- 只讀投影不包含老師內部備註、補強計畫或檢測內部識別碼；未複核結果不會顯示。

## 2026-08-21 — feat(assessment): 題庫匯入保留授權來源

<!-- release-notes: staff_update=staff-2026-08-21-question-bank-provenance -->

- 題庫匯入可保留來源名稱、版本、外部題號、年級、科目與授權參考。
- 標記為已授權素材的題目，缺少來源與授權資訊時會整批拒絕，不會留下半批資料。
- 題目仍先進入待審核；既有版本歷史、分校隔離與人工核准流程不變。

## 2026-08-21 — feat(assessment): 題庫管理與人工審核

<!-- release-notes: staff_update=staff-2026-08-21-question-bank-management -->

- 主任與老師可以依分校建立題庫、建立題目、標記知識標籤與 1–5 級難度。
- 支援嚴格 CSV 匯入；匯入題目一律先進入待審核，不會跳過人工審核。
- 題目修改採不可覆寫的版本歷史；主任可核准或退休題目，題庫資料不會寫入出缺勤、學習紀錄或帳務。

## 2026-08-21 — feat(assessment): 學習檢測與補強追蹤

<!-- release-notes: staff_update=staff-2026-08-21-learning-assessment-mvp -->

- 主任與老師可以建立、發布學習檢測，登錄學生多次結果並由主任審核。
- 從檢測結果直接建立知識缺口與補強計畫，追蹤待處理、進行中、完成與逾期數量。
- 檢測資料獨立於出缺勤、學習紀錄與帳務，不會因登錄檢測改寫既有教務資料。

## 2026-08-21 — ops(billing): 大安盧越上期誤標已繳回滾（SC1513）

<!-- release-notes: silent_ship=silent-2026-08-21-luyue-1513-unpaid -->

- 上期契約 `StudentClass` 1513（12,000）於 7/6 主任核帳誤標已繳；新增 guarded workflow 沖銷 Invoice 1070／Payment 1049 並還原未繳。
- 不碰本期 2828 與更早一期 325。詳見 `docs/incidents/lu-yue-1513-unpaid-rollback-manifest.md`。
- 修正 workflow 表名為 `payment_reports`（dry-run 曾因錯表名失敗）。

## 2026-08-20 — feat(ui): 家長首頁與夜間堂數檢查說明

<!-- release-notes: staff_update=staff-2026-08-20-nightly-session-check -->

- 家長登入後會先看到需要留意的請假、回覆、帳務、評量留言與今日課程，點選即可到正確分頁。
- 夜間堂數檢查清楚區分課程已用堂數、權威扣堂計算與出席證據；異常只供診斷與人工確認，不會自動改寫堂數，也不是銀行／學費對帳。

## 2026-08-20 — feat(ui): 轉移堂次改用課程選擇

<!-- release-notes: staff_update=staff-2026-08-20-course-transfer-picker -->

- 轉移已完成評量的堂次時，會先列出同一學生的其他課程，可以直接點選目標課程，不必背課程 ID。
- 仍可手動貼上課程 ID；系統繼續沿用後端的學生、分校與結算安全檢查。

## 2026-08-20 — feat(ui): 側欄改用工作情境分組

<!-- release-notes: staff_update=staff-2026-08-20-sidebar-workspaces -->

- 側欄改成「今日工作、教學現場、學生與課程、財務與人事、設定與資源」，主任找課程時會和學生管理放在同一組。
- 「課程管理」在導航中改名為「課程查找」；行事曆、出缺勤、學習評量留在教學現場。
- 桌面收合、手機底欄與更多功能補上目前頁面與圖示名稱，鍵盤與螢幕閱讀器比較容易知道自己在哪裡。

## 2026-08-20 — feat(ui): 課程管理回到唯讀營運鏡頭

<!-- release-notes: staff_update=staff-2026-08-20-course-triage-lens -->

- 課程管理頁現在先用來搜尋、篩選與找出需要注意的課程；建立、編輯、續報與加購請從「學生管理」進入。
- 行事曆移除合約層級的「改派合約」入口，避免同一份課程在不同頁面產生兩套狀態。

## 2026-08-19 — feat(billing): 收據紀錄標出班型、0 元原因與首堂來源

<!-- release-notes: staff_update=staff-2026-08-19-receipt-line-clarity -->

- 同一科目兩筆會寫出一對一／輔導／試聽與堂數，不必再對課程管理猜。
- 0 元會標試聽或輔導；歷史課不再只寫「尚未排課」，會說明堂次已取消或顯示合約開課日。預收判定仍只看有效堂次。

## 2026-08-19 — fix(billing): 帳務中心收據紀錄不再卡 PIN 卻沒輸入框

<!-- release-notes: staff_update=staff-2026-08-19-accounting-receipt-pin-gap -->

- 收據流水與已結清課程與催繳同一頁，登入後可直接查看。
- 不再出現「請輸入 PIN」但畫面沒有輸入欄。薪資、當月學收、老師管理仍要 PIN。

## 2026-08-17 — fix(calendar): 混班型時段一對三仍可再收（#1889）

<!-- release-notes: staff_update=staff-2026-08-17-mixed-class-type-occupancy-1889 -->

- 同一時段同時有一對二和一對三時，行事曆不再把整格打成已滿；一對三還能再收時會顯示剩餘名額。
- 加課與代課選老師也依「正在排的班型」算位子。本分校已滿會寫本分校，不會讓人以為是其他分校佔用。

## 2026-08-17 — fix(billing): 堂數制改月結時作廢未入帳帳單

<!-- release-notes: staff_update=staff-2026-08-17-billing-mode-convert-archive -->

- 課程從堂數制改月結（或反向）時，還沒入帳的帳單與待確認回報會自動作廢。
- 已經確認收款的收據金額不變；舊帳單若沒有計費模式快照，會補上記錄，之後開收據會提醒模式已變更。

## 2026-08-17 — docs(calendar): 混班型時段容量修復計畫（#1889）

<!-- release-notes: silent_ship=silent-2026-08-17-mixed-class-type-occupancy-plan -->

- 記錄 in-app #238 剩餘缺口：同一時段一對二與一對三並列時，不可用較嚴上限把一對三打成已滿。
- 本筆只有計畫與入口指標，沒有教職員可操作的產品變更。幽靈佔用修復仍見同日「同一天第二次調課」那筆。

## 2026-08-17 — fix(eval): 批次核准不再誤擋停用課；評量頁先出列表

<!-- release-notes: staff_update=staff-2026-08-17-lr-batch-approve-perf -->

- 課程已停用但堂次已上的待審評量，批次核准可以過，不再整批失敗。
- 學習評量表會先顯示列表，補建與課表資料在背景載入，進頁比較不會卡住。

## 2026-08-17 — fix(reports): 已完成堂次計入老師評量填寫率

<!-- release-notes: staff_update=staff-2026-08-17-fillrate-completed -->

- 主任看的老師評量填寫率，把狀態為「已完成」的課堂跟已到班、遲到一樣算進去。
- 代課老師的填寫率不會再因為堂次被標成已完成而漏掉。

## 2026-08-17 — chore(ops): 部署監看改 python3，不再依賴 jq

<!-- release-notes: silent_ship=silent-2026-08-17-wait-github-deploy -->

- 新增 `scripts/wait-github-deploy.sh`：用 `gh --json` + python3 等 `deploy.yml` 成功，再核對線上 `version.json`。
- 本機 WSL 沒裝 jq 時不再誤判部署失敗。

## 2026-08-17 — fix(schedule): 同一天第二次調課不再讓舊時段顯示已滿

<!-- release-notes: staff_update=staff-2026-08-17-same-day-reschedule-occupancy-1885 -->

- 同一天把課堂從一個時段改到另一個時段後，舊時段不再算已排滿，可以再排其他學生。
- 補課（還沒建成正式課堂）以及請假後另約的時段，仍會佔用老師時間。

## 2026-08-17 — fix(perf): 堂次寫入不再逐筆查課程結清鎖定

<!-- release-notes: silent_ship=silent-2026-08-17-session-settlement-lock-nplusone-1731 -->

- 新增大批課堂或改堂次狀態時，不再對每一筆重查課程是否已結清鎖定。
- 畫面與鎖定規則不變；已結清的課仍不能改堂次。

## 2026-08-17 — fix(ui): 預排日期不佔「第幾堂」

<!-- release-notes: staff_update=staff-2026-08-17-projected-ordinal -->

- 還沒建成正式課堂的預排日期，只顯示「預排」，不再插入已上課堂的編號。
- 已排進課表、尚未點名的堂次仍照順序編號。

## 2026-08-17 — fix(courses): 課程備註可存較長繳費說明

<!-- release-notes: staff_update=staff-2026-08-17-course-memo-length-1732 -->

- 課程備註加長，貼上給家長看的繳費說明也能存檔。
- 超過上限會提示請刪短，不再存檔失敗。

## 2026-08-17 — fix(ui): 課程管理已上堂數跟明細對齊

<!-- release-notes: staff_update=staff-2026-08-17-course-attended-count-1834 -->

- 課程卡片的已上堂數改跟展開後的日期列表同一套計算，避免剛點名的堂次沒算進去。
- 月結課不再誤顯示購買堂數。

## 2026-08-17 — fix(schedule): 堂數制還有餘額時不可結案吃掉補課

<!-- release-notes: staff_update=staff-2026-08-17-count-settle-makeup-1839 -->

- 堂數制課程還有未上堂次時，繳費頁／結案 API 會擋下，避免把請假順延的最後一堂取消。
- 若課程已被誤結案但還有餘額，主任仍可把剩下的堂次排進去。

## 2026-08-17 — fix(security): 主任密碼重設寫入稽核事件

<!-- release-notes: silent_ship=silent-2026-08-17-director-resetpw-audit-1813 -->

- 超級管理員代主任重設密碼時寫入 `security_audit_events`（操作者雜湊、對象雜湊）；不含臨時密碼與姓名。

## 2026-08-17 — fix(security): 主任核准／駁回寫入稽核事件

<!-- release-notes: silent_ship=silent-2026-08-17-director-approve-audit-1810 -->

- 超級管理員核准或駁回主任申請時寫入 `security_audit_events`（操作者雜湊、對象雜湊、舊→新身分代碼）；不含姓名／帳號明文。

## 2026-08-17 — fix(ui): LINE 設定與錯誤訊息再白話

<!-- release-notes: staff_update=staff-2026-08-17-ui-jargon-w2 -->

- 家長 LINE 設定改「頻道授權碼／頻道密鑰」；老師帳密匯出改中文表頭。
- 常見英文錯誤（沒有權限、找不到資料、需主任確認）會轉成中文再顯示。

## 2026-08-17 — fix(security): 手動調整堂數寫入稽核事件


<!-- release-notes: silent_ship=silent-2026-08-17-session-balance-audit-1811 -->

- 主任改 `SessionCount`／`RemainingSessions` 時寫入 `security_audit_events`（操作者雜湊、舊→新堂數）；不含學生姓名。
## 2026-08-17 — fix(ui): 主任畫面技術語言再清一波

<!-- release-notes: staff_update=staff-2026-08-17-ui-human-copy-sweep -->

- 老師批次匯入改中文欄位說明；收據／帳單編號不再顯示 LEGACY、INV 英文碼。
- 分校刷卡「Token」改「授權碼」；帳務錯誤訊息改中文。

## 2026-08-17 — fix(billing): 課程列表已繳改認足額收款（TD-083 B1）

<!-- release-notes: silent_ship=silent-2026-08-17-ispaid-b1 -->

課程管理列表的已繳判斷與帳務中心對齊：`Paid` 或帳單足額收款才算已繳；僅部分收款不再顯示已繳。重複入帳閘同步。

## 2026-08-17 — fix(security): 學生 Excel 匯出寫入稽核事件

<!-- release-notes: silent_ship=silent-2026-08-17-pii-export-audit-1812 -->

- `GET /api/v1/students/export` 成功匯出前寫入 `security_audit_events`（操作者雜湊、筆數、校區範圍）；不含姓名／電話等明文。
- 修正 `students/export` 被 `students/{student}` 搶路由的問題，並對 student id 加 `whereNumber`。

## 2026-08-17 — chore(billing): isFullyPaid 收斂到 StudentClass（TD-083 B0）

<!-- release-notes: silent_ship=silent-2026-08-17-ispaid-b0 -->

顯示用「已足額繳清」判斷改以 `StudentClass::isFullyPaid` 為單一來源；`AlertController` 改為委派。催繳列入條件未改。

## 2026-08-17 — docs: 重複功能清理 A→B→C 執行計畫

<!-- release-notes: silent_ship=silent-2026-08-17-dup-cleanup-plan -->

新增 [`docs/plans/DUP_FEATURE_CLEANUP_ABC_2026-08-17.md`](plans/DUP_FEATURE_CLEANUP_ABC_2026-08-17.md) 與 TD-082；產品行為不變。

## 2026-08-17 — chore(frontend): 移除未掛載死碼頁（重複功能清理 A）

<!-- release-notes: silent_ship=silent-2026-08-17-orphan-fe-dead-pages -->

刪除側欄已不使用的 `BillingList`／`PayReportPage`／`CoursePackagesPage`／`ClassesList`／`StudentWizard`／`TeacherProfilePage`；後端帳單／繳費回報／方案 API 不變。

## 2026-08-17 — fix(billing): 對帳與帳務中心掃讀密度

<!-- release-notes: staff_update=staff-2026-08-17-billing-scan-density -->

- 學生對帳改精簡摘要列；帳單主表可展開看收款時間線，異常只先顯示需處理項。
- 帳務中心催繳摘要改緊湊列；勾選後批次操作列會貼在畫面上方。

## 2026-08-17 — fix(billing): 帳務對帳改白話，拿掉技術符號

<!-- release-notes: staff_update=staff-2026-08-17-billing-human-copy -->

- 學生帳務對帳拿掉 AR Ledger、Invoice／Payment 等英文；「帳單／課程」改「帳單（科目）」。
- 帳務中心與課程管理改「多收待處理」「撤銷收款」「帳單與對帳」等主任用語。

## 2026-08-17 — fix(e2e): UI Smoke 不再被版本公告擋住導覽

<!-- release-notes: silent_ship=silent-2026-08-17-ui-smoke-overlay -->

- Playwright 關閉版本公告改走真實「稍後再看」點擊（不用 force）；無學生時不硬性要求「帳務資料」分頁。
- WebDriver 工作階段不自動彈出版本公告，避免 pointer-events 層攔截側欄。

## 2026-08-16 — docs(ops): 夜間對帳正名為堂數一致性檢查

<!-- release-notes: silent_ship=silent-2026-08-16-nightly-session-reconcile-clarity -->

- 系統管理員側欄改稱「夜間堂數對帳」，並寫明這是已用堂數 vs 權威扣堂口徑，不是銀行或學費勾稽。
- 排程登錄 domain 從誤標的 payment 改為 session_deduction；命令仍只診斷、不改堂數。

## 2026-08-17 — fix(billing): 帳務中心白屏（activeTab 宣告順序）

<!-- release-notes: staff_update=staff-2026-08-17-tuition-collect-tdz -->

- 帳務中心進入時因 `activeTab` 使用早於宣告而崩潰；調整宣告順序後可正常載入名單。

## 2026-08-16 — feat(payroll): 正職現金加扣款

<!-- release-notes: staff_update=staff-2026-08-16-fulltime-cash-adj -->

- 現金加扣款是獨立金額，不進倍率；主任確認、總部核准後才加進總發放。已鎖定月份不能新增。

## 2026-08-16 — feat(payroll): 正職行政加給與底薪核准畫面

<!-- release-notes: staff_update=staff-2026-08-16-fulltime-admin-allowance -->

- 行政加給 0–10% 與底薪：主任確認／待核准，總部核准後才進倍率；現金加扣款下一包。

## 2026-08-16 — fix(billing): 回報提示改請主任確認入帳

<!-- release-notes: staff_update=staff-2026-08-16-hai-sen-director-copy -->

- 已有待對帳時改提示到帳務中心確認入帳或退回，不再寫請會計。
- 嗨森無會計角色；兩步驟不變，主任核完帳即入帳開收據。

## 2026-08-16 — fix(billing): 帳務中心不擋 PIN，確認入帳由主任完成

<!-- release-notes: staff_update=staff-2026-08-16-director-confirms -->

- 帳務中心不再走 PIN 卸載，一進去就載入催繳名單。
- 文案改為主任對到帳後按確認入帳；不假設有獨立會計。

## 2026-08-15 — fix(payroll): 已核准可退回，全校放假改讀課程管理

<!-- release-notes: staff_update=staff-2026-08-15-eligibility-approved-revert -->

- 已核准的薪資補登可按「退回」，該筆不再進入薪資。
- 全校放假改讀課程管理連假／堂次請假，不必再手動登假日曆。

## 2026-08-14 — chore(ops): 張正甯／張正樂國文續購 8 堂未入帳作業閘門

<!-- release-notes: silent_ship=silent-2026-08-14-chinese-renewal-unpaid -->

- 新增只允許來源批次 `1681`／`1682` 的續購作業：8 堂、原金額、未付款，並走正式 `purchase-batch`。
- 預設 dry-run；apply 需要確認字串，且不會入帳、不會移 8/5、不會使用數學批次。

## 2026-08-16 — fix(billing): 課程管理學生列顯示課程資料／帳務資料

<!-- release-notes: staff_update=staff-2026-08-16-billing-tabs-visible -->

- 課程管理每位學生姓名下方整列顯示「課程資料｜帳務資料」，不必先展開、也不用找右側小字。

## 2026-08-16 — fix(billing): 帳務入口可見性與待對帳用語

<!-- release-notes: staff_update=staff-2026-08-16-billing-ux-find -->

- 課程管理把「課程／帳務」分頁放到學生列標題，收合時也能直接點帳務並展開。
- 帳務中心等 PIN 頁在解鎖前顯示說明，不再一片空白；待核帳改稱待對帳，勾選左欄才出現批次列。

## 2026-08-16 — feat(payroll): 正職結算可鎖定、匯出、調整與行政加給

<!-- release-notes: staff_update=staff-2026-08-16-fulltime-payroll-lock -->

- 正職結算單可鎖定凍結金額（有試算列不能鎖）、總部可重開、可匯出 CSV（Excel 可開）。
- 已鎖定月份不能回溯改底薪。全勤／勞健保／行政加給仍未自動列入。

## 2026-08-16 — feat(billing): 課程頁帳務分頁與批次回報 (#1827)

<!-- release-notes: staff_update=staff-2026-08-16-reported-paid-phase2 -->

- 課程管理同一學生可切「帳務資料」，不必再到收費頁重搜姓名。
- 收費頁可勾選多筆批次回報或確認入帳；批次確認不會自動開收據。

## 2026-08-14 — chore(ops): 測試主任 285 唯讀清理診斷

<!-- release-notes: silent_ship=silent-2026-08-14-director-285-diagnose -->

- 新增只允許 `user_id=285` / `w3-director-test-20260813` / campus `9` 的 GitHub Actions 唯讀診斷；身分不符即停止。
- 不刪除帳號、不改 production 資料；正式刪除仍走 super_admin 的主任管理頁。

## 2026-08-16 — fix(reports): 代課老師填報率計入代課老師

<!-- release-notes: staff_update=staff-2026-08-16-fillrate-substitute-absent-copy -->

- 主任「評量填寫率」改與課表同一套代課解析，代課堂次計入代課老師，不再算在原契約老師或消失。
- 點名確認：缺席改為不扣堂、不順延；請假仍不扣堂並順延。

## 2026-08-16 — feat(billing): 通知中心與批次 API 改待對帳 (#1827)

<!-- release-notes: staff_update=staff-2026-08-16-reported-paid-notif -->

- 通知中心學費按鈕改為送出已回報，課程仍未繳，等會計確認才入帳開收據。
- 新增批次回報／批次確認 API（最多 40 筆），給下一版收費頁勾選使用。

## 2026-08-16 — feat(payroll): 正職薪資要件改為 115.07 結算表

<!-- release-notes: staff_update=staff-2026-08-16-fulltime-settlement-table -->

- 主任頁改為正職結算欄：底薪、正課／輔導試聽／核薪科目數、一對三、科目數與一對三獎金、倍率拆解、倍率後獎金算式、16 段課加扣款、總發放金額。
- 假日 16 小時改依規定以常態排課加假日假抵扣滿 16 小時給 10%，否則 0%。科目數非整數依附件 1–50 表相鄰列內插；一對三與正課分桶計算。
- 全勤、勞健保、行政加給（TD-077）仍不自動列入。

## 2026-08-16 — feat(billing): 行政登錄已回報，會計確認後才入帳開收據 (#1827)

<!-- release-notes: staff_update=staff-2026-08-16-reported-paid-pending -->

- 行政在課程管理／收費頁「登記已回報」後，課程仍是未繳費（待對帳），不會立刻變已繳或開收據。
- 會計在催繳名單對「待核帳」按確認入帳後，才標記已繳並可開電子收據；對不到款可退回。

## 2026-08-16 — docs(arch): 行政已回報與會計入帳核銷拆分計畫（#1827）

<!-- release-notes: silent_ship=silent-2026-08-16-reported-paid-accounting-split-rfc -->

- 新增繳費狀態機 RFC：行政先登錄「已回報」，會計對帳後才標已繳並開收據；第二步才做課程頁同畫面與批次。
- 本筆只有計畫與入口指標，沒有教職員可操作的產品變更。

## 2026-08-16 — fix(eval): 已上改回未點再標到班時自動還原評量草稿

<!-- release-notes: staff_update=staff-2026-08-16-lr-resurrect-status-adjust -->

- 堂次從「已到班」改回「未點名」再改回「到班」時，系統作廢的評量會自動恢復為待填，老師端不再空白。
- 不改請假／手動作廢評量的規則；人工作廢仍不會自動復活。



<!-- release-notes: staff_update=staff-2026-08-15-stale-receipt-badge-934 -->

- `Invoice` 新增 `ScheduleModeAtIssue`（開立當下的計費模式快照，純新增欄位，舊資料 NULL，不回填）。
- 課程計費模式（堂數制/月結）事後變更時，收據 API 會標示 `billing_mode_changed`，前端顯示提醒；不自動作廢、不改動任何金額或已結帳資料。
- 範圍縮小：只修「舊收據看起來仍有效」的顯示問題；黃玟睿本案實際應收金額仍待校方確認，不由本次變更決定。

## 2026-08-15 — chore(schedule): TD-076 Phase 3 回填命令（預設 dry-run）

<!-- release-notes: silent_ship=silent-2026-08-15-td076-phase3-backfill -->

- 新增排課原始時段回填命令，預設只報告、不寫入；正式寫入需修復閘道，本包不執行。
- 教職員調課與畫面不變，也不打開新旗標。

## 2026-08-15 — chore(schedule): TD-076 Phase 2 雙寫身分欄（旗標預設關）

<!-- release-notes: silent_ship=silent-2026-08-15-td076-phase2-dual-write -->

- 排課表新增可空的原始日期／時間欄，以及只追加的改期紀錄表；預設不啟用，調課行為與現在相同。
- 不改日曆／課程管理讀取路徑，也不加唯一鍵。

## 2026-08-15 — docs(arch): TD-076 Phase 0 盤點與舊 bug 鎖測

<!-- release-notes: silent_ship=silent-2026-08-15-td076-phase0-inventory -->

- 只補排課身分計畫的寫入／讀取清單，以及防止 R102／R103／請假扣堂／鎖老師復發的測試。
- 不改 `schedules` 寫入形狀，不遷移 production。

## 2026-08-15 — chore(bug): 已結案回報可補一則公開說明

<!-- release-notes: silent_ship=silent-2026-08-15-resolved-bug-followup -->

- 內部追蹤流程允許在已標成修好的回報上再留一則說明，不會改狀態。
- 教職員畫面與操作不變。

## 2026-08-15 — fix(billing): 陳姝彣收帳顯示改回合約金額

<!-- release-notes: staff_update=staff-2026-08-15-tuition-charge-display-1734 -->

- 收帳列表對已收款、且帳單已是正確總額的課程，不再顯示過期的錯誤合約金額。
- 只改顯示用合約金額；已開立帳單與實收紀錄不變。

## 2026-08-15 — fix(students): 搜尋含表情符號不再讓學生名單崩潰

<!-- release-notes: silent_ship=silent-2026-08-15-student-name-utf8mb3-like -->

- 學生／帳務／評量姓名搜尋會先去掉 4-byte 字元再查 utf8mb3 欄位，避免 SQL collation 錯誤。
- 純表情符號搜尋回傳空結果，不會列出全部分校學生。

## 2026-08-15 — fix(teacher-home): 未來堂次帶分校，不再顯示 Branch #0

<!-- release-notes: staff_update=staff-2026-08-15-teacher-home-projected-campus -->

- 教師週課表尚未產生實體堂次時，會帶學生所屬分校；缺分校時顯示中文或隱藏標籤，不再出現內部編號。
- 今日待辦與週課表改讀同一套課堂資料。

## 2026-08-15 — docs(gov): Agent 是操作者（不再等人點頭）

<!-- release-notes: silent_ship=silent-2026-08-15-agent-operator -->

- 艦隊政策改為 Agent 負責 merge / 關 issue / 寄任務信 / dispatch 已提交的 workflow。
- AllTrue 仍禁 Pi SSH、印 secrets、force-push。R3 要 Repair Manifest，不要 Founder 橡皮圖章。

## 2026-08-15 — docs(gov): 艦隊 merge 政策指標（R0–R2 驗收後 Agent 合入）

<!-- release-notes: silent_ship=silent-2026-08-15-fleet-merge-pointer -->

- 誰可以 merge 以 portfolio-ops `AUTONOMY_POLICY` 為準；AllTrue 只分類風險與產品 P0，不再把 R0–R2 merge 禁回去。
- Required GitHub checks 綠了由 Agent squash-merge；R3 與額外 production 變更仍是 Founder。

## 2026-08-15 — docs(arch): 排課 occurrence 身分根治計畫（TD-076）

<!-- release-notes: silent_ship=silent-2026-08-15-schedule-occurrence-identity-rfc -->

- 新增工程主線與 RFC：禁止整包重寫；排課改期改為穩定 occurrence 身分（計畫階段，無產品行為變更）。
- Agent（Claude Code / Codex / Cursor）從 `AGENTS.md` / `CLAUDE.md` / INDEX 可找到同一份計畫。

## 2026-08-14 — fix(payroll): 待審核薪資補登可修改與撤回

<!-- release-notes: staff_update=staff-2026-08-14-eligibility-pending-edit -->

- 資料補登預設改為老師請假／補課；全校放假請走課程管理「連假批次請假」，假日曆僅作少用補登。
- 待審核資料可在右側清單修改或撤回；已核准後仍不能改，也不會誤算進薪資。

## 2026-08-14 — fix(ui): 月結課不再顯示購買堂數，老師清單同一堂不重複

<!-- release-notes: staff_update=staff-2026-08-14-monthly-copy-teacher-list -->

- 月結課程的上課日期改顯示已上堂數，不再把 `SessionCount` 寫成「購買 N 堂」。
- 行事曆老師清單對同一學生、同一星期、同一開始時間只保留一筆，避免舊契約與現行課程並列。

## 2026-08-13 — feat(payroll): 正職老師薪資要件頁面新增底薪與總發放金額

<!-- release-notes: staff_update=staff-2026-08-13-fulltime-settlement-total-payout -->

- 「正職老師薪資要件」頁面新增可編輯底薪欄位，並依既有六項符合要件（每週16段、假日16小時、平日下午課、特殊表現、扣除、科目數獎金）組成教師倍率與總發放金額。
- 已知缺口：目前六項要件未涵蓋公告中的「行政加給倍率」（行政協助／總導師／副主任，0～10%），需另外排單補上。

## 2026-08-13 — fix(course): 上線堂次跨續購批次的 owner 修復命令

<!-- release-notes: staff_update=staff-2026-08-13-session-entitlement-transfer-command -->

- 新增預設唯讀的 dry-run 命令；正式操作必須同時提供 execute、force、production repair gate 與不可覆寫的 JSON snapshot。
- 支援執行後 verify 與 drift-safe rollback，供已確認的超額堂次在不更動付款、發票或收據的前提下轉至續購批次。

## 2026-08-12 — fix(course): 建立堂次跨續購批次轉移的可稽核核心

<!-- release-notes: silent_ship=silent-2026-08-12-session-entitlement-transfer-core -->

- 新增 transaction、稽核快照、執行後驗證與 drift-safe rollback 的 domain service；同步堂次、評量、點名與扣堂帳，但不更動發票、付款或收據。
- 本 PR 只有 backend domain 與測試，沒有 UI 或可執行命令，無員工操作變更；正式 owner-gated 命令與員工更新由後續獨立 PR 上線。

## 2026-08-12 — fix(in-app-bugs): 排課、收費與收據顯示修正（#228–#233）

<!-- release-notes: staff_update=staff-2026-08-12-in-app-bug-fixes -->

- 共用方案新增堂次會正確使用跨課程的剩餘堂數，不再誤判單一課程已滿；收帳提醒改用課程合約費率與堂數計算，收據期間改以實際堂次日期為準。
- 行事曆會補回遺失的正常改期堂次；課程管理只修改備註或其他非排程資料時，不會意外重建或新增預排堂次。
- **詳見**：in-app #228–#233；相關回歸測試涵蓋包堂容量、課堂投影、收費提醒、收據期間與課程更新流程。

## 2026-08-09 — fix(payroll): 正職薪資假日假與常態排課規則對齊

<!-- release-notes: staff_update=staff-2026-08-09-payroll-director-rules-v2 -->

- 假日假改為「維持資格、不創造時數」：常態假日16小時請假不扣假日倍率與每週16段獎金；常態不足16小時不因假日假產生10%倍率。
- 平日下午倍率只採固定存在於學生課表的常態正課，排除補課／臨時加課；常態5.5小時為0.75段，固定到22:00的完整段不因當日到21:30被截短。
- 報表新增假日常態排課基準、假日假中性效果與排除課程原因；缺少來源分類時不猜測為有效課程。
- 測試涵蓋假日8+8不產生倍率、常態16小時請假保留倍率、4／5／5.5／6小時換算、重疊課表與補課排除。

## 2026-08-08 — fix: 請假補課候選改以原堂日期為基準

<!-- release-notes: staff_update=staff-2026-08-08-makeup-candidate-date-fix -->

- **Fixed**：原堂是 8/20 的請假案件，不會再出現 8/9 這類原堂之前的補課候選；畫面會直接顯示可安排的日期範圍。
- 開發備註：前端與 `ExceptionWorkflowCandidateGenerator` 後端同步套用「原堂後一天」邊界，並新增 API regression test。

## 2026-08-08 — security(ops): #1387 三階段具名 DB principal 輪替（待 Founder 審核／未執行）

<!-- release-notes: silent_ship=silent-2026-08-08-staged-principal-rotation -->

- **流程**：在唯一允許的 `.github/workflows/deploy.yml` 內新增 Founder-only 的 Phase 1 建立＋複製 `SHOW GRANTS`、Phase 2 雙帳號存活期間切換＋具名 DB read 驗證、Phase 3 人工觀察後鎖舊帳號；三階段各有獨立 typed confirmation，絕不自動串接，沒有新增 standalone workflow。
- **驗證**：Phase 2 除 health/version 外，強制 Laravel 新連線回報新 principal 並執行 `SELECT 1`，另以 `schedule:list` 驗證 scheduler graph 可在新 config 下啟動；PHP-FPM reload 失敗視為 hard failure 並自動還原該次 `.env`。
- **拓撲修正**：7 個 production workflow 與 3 支 production-oriented script 原本把 `.env` 的新密碼配上寫死的 `admin` username，已改成 username/password 同源讀取；CI/local-only fixture 不變。
- **安全邊界**：沒有觸發 workflow、沒有連 production、沒有執行 DB/SSH、沒有改 backend PHP；實際 grant 形狀、server lock 支援與端對端結果仍待 Founder 執行確認。
- **詳見**：`docs/incidents/1387-staged-rotation-runbook.md`、`docs/AI_REGRESSION_LESSONS.md` R106。

## 2026-08-08 — fix(ops): DB 密碼輪替 workflow 的 `ALTER USER` host 寫死錯誤，導致 2026-08-07 Founder 觸發失敗

<!-- release-notes: silent_ship=silent-2026-08-08-db-password-rotation -->

- **背景**：SEC-ALLTRUE-003 的密碼輪替最後一步（Founder-only）2026-08-07 執行失敗，錯誤是 `ERROR 1396: Operation ALTER USER failed`。
- **根因**：連線用 `-h 127.0.0.1`（驗證身分是 `@'127.0.0.1'`/`@'%'`），但改密碼硬寫 `@'localhost'`，兩者是不同帳號，MySQL 找不到要改的那一列。
- **修法**：`ALTER USER '${DB_USER}'@'localhost'` 改成 `ALTER USER CURRENT_USER()`，一定改到實際連線驗證通過的那個帳號。
- **範圍**：只修 workflow 腳本本身；未觸發輪替，實際執行仍待 Founder。
- **詳見**：`docs/AI_REGRESSION_LESSONS.md` R104。

## 2026-08-08 — fix(calendar): 行事曆會畫出課程管理否認存在的孤兒改期堂次（in-app #225/#226/#227）

<!-- release-notes: staff_update=staff-2026-08-08-calendar-stability -->

- **背景**：三筆同分校回報（#225 10:03、#226 10:39、#227 11:00）文字幾乎一樣：「行事曆有，課程管理沒有」。#225 早於當天任何部署，是既有問題；查證後發現這跟同一天稍早修的「鬼影方框」（in-app #225 原本被誤標成這個，已更正見 R103）是不同症狀。
- **根因**：行事曆合併邏輯會把沒有對應已物化 `ClassSession` 的 `scheduled` 改期例外仍然畫成一堂課；課程管理只讀已物化列，看不到這種孤兒例外。
- **修法**：`calendarOccurrenceMerge.js` 新增守衛，改期目的地例外若找不到對應 `ClassSession` 就不合成 occurrence。純前端顯示層修正。
- **測試**：`calendarOccurrenceMerge.js` 新增合成回歸案例（無 production DB 存取權限，標註為 synthetic），revert-proof 已人工驗證。
- **詳見**：`docs/AI_REGRESSION_LESSONS.md` R103、GitHub #1690。

## 2026-08-08 — chore: GitHub governance 收尾（#876/#879/#880）— Solo/Multi 審查切換文件、tag 保護、secret 輪換自動提醒

<!-- release-notes: silent_ship=silent-2026-08-08-github-governance -->

- **#876**：`RISK_BASED_MERGE_POLICY.md` 新增「Solo vs Multi-maintainer」切換表——目前單人模式維持 0 approval（既有 Founder Decision），新增第二位維護者時要改哪些設定（`required_approving_review_count`、`require_code_owner_review` 等）已明確列出。
- **#879**：Private vulnerability reporting 確認已啟用、0 筆待處理 advisory；secret 輪換提醒改成每 90 天自動開 issue（`.github/workflows/secret-rotation-reminder.yml`），不再依賴人工手動建立；`SEVERITY_MATRIX.md` 新增 security alert 的 P0-P2 SLA 對照。
- **#880**：新增 `release-tag-protection` ruleset（`refs/tags/v*` 禁刪除/禁移動)，避免 release tag 被誤刪影響 `RUNBOOK_ROLLBACK.md` 的回滾路徑；`REF_GITHUB_RULESET_BASELINE.md` 記錄現況快照；`OPERATIONAL_CONSISTENCY_CHECK.md` 新增 Rule 8 做月度漂移檢查。
- **未做**：`#871`（Merge Queue）另外評估，因為會改變合併機制本身，風險與本次文件/唯讀 API 設定不同級別，不在本次一併處理。

## 2026-08-08 — fix(calendar): 改期規則從「同天取最新」精修為「同格已被更新的改期標記取代」（in-app #225，木柵陳宥翰 SC#1249）

<!-- release-notes: staff_update=staff-2026-08-08-calendar-stability -->

- **背景**：`#1685`（本檔前一則）上線幾分鐘後，主任又回報同分校另一筆課「8/7 行事曆有、課程管理沒有」（in-app #225），查證是同一類問題的另一種變體：這次調課鏈連續改了三次，最後一次的目的地落在**不同一天**（8/7 → 最終到 8/8）。`#1685` 的第一版修法（同一天取 id 最大的 scheduled 標記）沒辦法涵蓋這種情況——8/7 那筆被取代的紀錄沒有「同一天更新的 scheduled 標記」可以輸給它，因為真正取代它的下一步改到了 8/8。
- **修法**：把判斷規則從「同課程同日期取最新」改成更精確的「同課程＋同日期＋同時段，若有一筆更新（id 更大）的 rescheduled 標記，代表這個 scheduled 標記已被取代」——不管改期後的新目的地落在哪一天，都能正確判斷。同時補上 `#1685` 那筆真實案例（吳艾潼 SC#2688）與這次的新案例（陳宥翰 SC#1249）作為回歸測試，確認新規則兩案都涵蓋。
- **測試**：`calendarExceptionMerge.test.js` 新增以真實 `schedules` id 為本的兩組案例（SC#2688 id 7583/7584/7588/7589、SC#1249 id 7138/7139/7207/7208/7422/7423）。

## 2026-08-08 — fix(calendar,course-mgmt): 木柵吳艾潼 8/8 行事曆重複時段 + 月結課誤判超排

<!-- release-notes: staff_update=staff-2026-08-08-calendar-stability -->

- **背景**：主任回報木柵吳艾潼 8/8 的課「課程管理只有一堂，行事曆卻出現兩個時段」，且這門月結課在課程管理被標成「超排」。
- **根因 1（行事曆重複時段）**：`schedules` 表這門課 8/8 當晚被連續調課兩次，第二次調課重新提交到跟第一次「相同」的時間（14:30），後端 `ScheduleController::store()` 的防重複刪除邏輯是照「精確舊時段時間」比對，沒抓到這種「調到同一個時間」的邊界情況，留下一筆已被取代但狀態仍是 `scheduled` 的舊紀錄（id 7584）跟最新的紀錄（id 7589）並存。前端 `shouldRenderScheduledException()` 沒有處理「同一課程同一天有多筆 scheduled 標記」的情況，兩筆都會被畫出來。
- **根因 2（月結誤判超排）**：`StudentClassController` 不管課程是不是月結，一律把 `sessions_purchased` 設成 `SessionCount`；`isOverQuotaSession()` 只排除了包堂（PackageID）課程，沒有排除月結課程，導致月結課只要材質化堂數超過 `SessionCount` 這個（其實不該當作上限的）數字，就被誤標超排。月結本來就沒有「買幾堂」的概念。
- **修法**：前端 `shouldRenderScheduledException()` 同課程同日多筆 `scheduled` 標記時，只採信 id 最大（最新）那筆；`isOverQuotaSession()` 加上 `isSessionMode(course)` 檢查，月結課程一律略過超排判斷。均為前端顯示層修正，未改動任何 production 資料或後端調課紀錄。
- **測試**：`calendarOccurrenceMerge.test.js` 新增以本案真實 SC#2688／id 7584/7589 為本的回歸案例；`useCourseSessionsDisplay.test.js` 新增月結課不誤標超排的回歸案例。
- **未解決（需人工確認，非本次範圍）**：這門課目前確實有 5 筆佔堂數的紀錄對上 `SessionCount=4`（3 已上 + 8/8 補課例外 + 8/9 正常堂），這是否為合理的補課多算、還是 7/26 請假轉補課的邏輯少頂替了原堂號，屬於業務判斷，需主任/你確認，未在本次一併修正。

## 2026-08-08 — fix(scheduling): #170 修復上線後生產資料仍卡住，補上夜間自我修復掃描

<!-- release-notes: staff_update=staff-2026-08-08-leave-review-integrity -->

- **背景**：稍早的 #170 修復（`voidLiveArtifactsForLeave()` 抽出共用作廢邏輯，接到 `ExceptionWorkflowController::confirmCandidate()`）上線後，用新增的唯讀診斷 workflow 重新查詢，發現 `bugs:verify-reproductions` 的 `leave_session_with_live_learning_record` 條件當晚（8/8 04:00）**仍然 REGRESSED**——同一筆 `ClassSession #15635` / `LearningRecord #11828` 依然存在。
- **根因**：程式碼修復只能防止「未來」呼叫 `confirmCandidate()` 時發生同樣問題，對「修復上線前就已經被寫壞」的既有資料完全無感——這是修 code path 與修「已經髒掉的資料」是兩件不同的事，只做前者不代表後者會自動消失。
- **修法**：仿照既有 `learning-records:backfill-missing`（處理「缺 LearningRecord」的鏡像問題）的夜間自我修復掃描模式，新增 `learning-records:void-stale-leave`：每晚 03:55（`bugs:verify-reproductions` 04:00 之前）掃描所有 `leave`/`leave_adjusted` 堂次，凡是還掛著未作廢 `LearningRecord`／`StudentSingIn` 的，一律透過既有共用邏輯 `CourseLeaveCascadeService::voidLiveArtifactsForLeave()` 作廢——不管是哪個尚未發現的程式路徑造成的，都會被這道每日掃描自動清掉，而不必每次都靠人工一筆一筆 SSH 進生產環境修資料。冪等、唯讀性質（只作廢已經該被作廢的資料，不新增/不刪除任何堂次或核准評量以外的內容）。
- **測試**：新增 `LearningRecordVoidStaleLeaveTest`（作廢 LearningRecord/StudentSingIn、冪等重跑、不誤觸非請假堂次的正常評量）。
- **記錄**：`ops/critical-job-registry.json` 同步登記新排程任務。
- **驗證**：部署後手動觸發（`.github/workflows/learning-records-void-stale-leave-manual.yml`）跑了一次，`total voided: 1`，即時重跑 `bugs:verify-reproductions --json` 確認 `leave_session_with_live_learning_record` 轉為 `count: 0, state: FIXED-OK`——ClassSession #15635 / LearningRecord #11828 這筆卡了 5 天（8/3–8/8）的資料已在生產環境實際清除，非僅程式邏輯測試通過。

## 2026-08-07 — fix(billing): 繳費提醒金額計算 N+1，量大時效能劣化（#984）

- **背景**：#984 一開始只看程式碼判斷「查詢已經批次過，沒有活躍 bug」，實際上還有一層沒發現——回歸測試才抓到真正的 N+1。
- **根因**：`AlertController::subjectLabel()` 呼叫 `StudentClass::displaySubjectName()`，當 `StudentClass.Subject` 欄位是空字串時，會逐筆查 `Subject`/`BaseData` 表。CI 顯示 5 筆未繳費課程要跑 24 條查詢，40 筆要跑 94 條（每筆多跑約 2 條）。
- **修法**：`tuition()` 呼叫時一次性批次解析 `SubjectID → name`，不再逐筆查表。純效能修正，未改變任何金額計算邏輯。
- **詳見**：PR #1667。

## 2026-08-05 — fix(calendar): 編輯不相關的續約合約後，舊合約已完成的堂次從行事曆消失（in-app #220/#221）

- **背景**：學生張姸耣較舊、已結束合約的 5/30 已上堂次，在主任編輯另一筆完全無關的續約合約（同科目同老師、不同日期）後，從行事曆上消失。直接查 DB 確認 `ClassSession` 資料列從未被動過——純前端快取問題，不是資料遺失。
- **根因**：`useCalendarDataLoad.js` 的 `loadCourses()` 依目前畫面週次（含預抓緩衝）撈 `class-sessions`，用回傳結果整批覆蓋共用的 `sessionDatesByCourseId` 快取，導致其他課程原本已載入、但這次查詢視窗外的堂次被覆蓋清空。
- **修法**：改成依課程分別合併快取，而不是整批覆蓋。
- **詳見**：PR #1641。

## 2026-08-05 — fix(duplicate-review): 重複堂審核頁的分校篩選對 super_admin 沒作用（in-app #216）

- **背景**：super_admin 在重複堂審核頁選分校下拉選單，清單內容不會變。
- **根因**：`p2-review` 從未讀取 `?campus_id=` 查詢參數；一般角色靠 `auth_campus_ids` 天生限制分校範圍，但 super_admin 沒有這個限制，所以少了這段就完全篩不動。
- **修法**：新增 `effectiveCampusIds()`，在既有 `allowedCampusIds()` 範圍上疊加可選的 `campus_id` 參數——super_admin 可篩到任一分校；非 super_admin 仍只能在自己原本可見範圍內再縮小，不能跳出權限範圍。
- **詳見**：PR #1638。

## 2026-08-05 — fix(course-mgmt): 暫停課程恢復後，被取消的堂次沒有一起復原（in-app #219）

- **背景**：學生侯思圻 7/29 的試聽課被排入，課程因故自動歸類為「history」（暫停，`Stop=1`），主任按「恢復課程」後，這堂課仍然沒有回到行事曆上。
- **根因**：`togglePause()` 的暫停分支會取消該課程未來所有 `scheduled` 狀態的 `ClassSession`（標記 `[暫停取消]`／`[結案取消]`；孤兒停用課程則由 `FixOrphanScheduledSessions` 標記 `[孤兒停用取消]`）。但恢復分支只重設了 `Stop`／`closed_reason`，從未把這些被取消的堂次復原。
- **修法**：恢復課程時一併復原暫停/孤兒停用取消的堂次。
- **詳見**：PR #1639。

## 2026-08-07 — fix(calendar): 逐堂手動排課的堂次若時段偏離課程預設時段，取消/角標會找不到對應堂次（in-app #224）

- **背景**：主任回報 8/8 一堂課「無法移動或刪除」。查證發現該類堂次是透過 #211（逐堂手動排課，2026-08-02 上線）新增，開始時間由使用者自由輸入、不必等於課程契約預設時段。
- **根因**：`SmartCalendar.vue::findSessionRowForCell()` 比對「這個格子對應哪一筆 ClassSession」時，先要求 row 時間完全等於課程預設時段，只有調課例外才會退回同日任一筆；負責畫方塊的 `resolveAllCourseGridTimesForDate()` 沒有這個限制，方塊看得到，但點開後「取消本堂」按鈕與點名/評量角標卻找不到對應資料而消失，且無任何錯誤提示。屬架構缺口：專案裡已有一套正確、有測試的同類實作（`classSessionPick.js::resolveSessionIdForSubstitute()`），`findSessionRowForCell()` 是另一份沒有共用它的 page-local 副本（對應既有技術債 GitHub #1041）。
- **修法**：`classSessionPick.js` 新增可回傳完整 row 的共用函式 `resolveSessionRowForCell()`，`findSessionRowForCell()` 改為呼叫它，移除只有調課例外才退回同日任一筆的限制。純前端顯示/互動修正，不涉及後端扣堂或資料寫入路徑。
- **測試**：`classSessionPick.test.js` 新增偏離時段仍可比對、exact match 不回歸、跨日期不誤命中等 case；`npm run test:calendar` 全綠。
- **詳見**：`docs/AI_REGRESSION_LESSONS.md` §R101、GitHub #1671（分診）。

## 2026-08-07 — docs: INDEX §III.10 補上 #957 被取代 runbook 的 SUPERSEDED 導覽指標（#1560）

- **背景**：`docs/runbooks/957-d1-pcr.md` 頂部已自我標示 `SUPERSEDED — 見 957-d1-pcr-r2.md`，但 `docs/INDEX.md` §III.10（#957 D1 Sprint）仍只連結舊檔——讀者從 INDEX 進入會拿到已被取代的 PCR 指引。
- **修法**：在 INDEX 該連結後補上 `（SUPERSEDED，見 957-d1-pcr-r2.md）` 並加上取代文件連結。純 docs 導覽修正，無任何 runbook 內容或 production code 變更。
- **影響**：文件讀者不再被導向已被取代的 runbook，INDEX 與 runbook 自我標示恢復一致。

開發備註：R0 docs、快速合併（squash `92fe364599`）。詳細變更見 PR #1560。

## 2026-08-07 — fix(scheduling): 家長請假補課確認時，原堂次遺留的待審評量未作廢（#170 nightly 回歸）

- **背景**：巡查 Gmail 系統通知時發現 `Pi Health Monitor` GitHub Actions 連續多天紅燈（8/3–8/7）。追查發現根因是每晚 04:00 執行的 `bugs:verify-reproductions`（#1080 bug 終結閘門）持續 `exit 1`——透過新增的唯讀診斷 workflow（`.github/workflows/lr-missing-diagnose.yml`，SELECT-only，不寫入任何資料）對生產資料庫做即時查詢，找到當天實際觸發的條件是 `leave_session_with_live_learning_record`（#170）：`ClassSession #15635`（2026-07-04，Status=`leave`）身上仍掛著一筆未作廢的 `pending` `LearningRecord`（#11828）。
- **根因**：`ExceptionWorkflowController::confirmCandidate()`（家長請假 exception workflow 由主任確認補課候選時）直接把原堂次 `Status` 改成 `leave`，但沒有作廢該堂次既有的 `LearningRecord`／`StudentSignIn`——與 `CourseLeaveCascadeService` 既有的互動式請假路徑（會正確作廢）不一致。若 exception workflow 處理延遲、原堂次日期已過，夜間 `learning-records:backfill-missing` 會先幫還是 `scheduled` 狀態的堂次建立一筆 `pending` 評量佔位，之後才被 `confirmCandidate()` 改成 `leave`，殘留的評量就沒人清。`LearningRecord::scopeExcludeLeaveSessionPendingReview()` 這個既有的查詢層過濾器只是把症狀（待審清單顯示錯誤）擋掉，底層資料本身仍是錯的——這正是 `bugs:verify-reproductions` 這道「防止症狀被治標掩蓋」閘門存在的意義。
- **修法**：把 `CourseLeaveCascadeService` 原本 inline 的「作廢 LearningRecord/StudentSignIn」邏輯抽成共用的 `voidLiveArtifactsForLeave()`，作為「堂次轉為請假」唯一的作廢邏輯出處，`ExceptionWorkflowController::confirmCandidate()` 在把原堂次改成 `leave` 前呼叫同一個方法。
- **測試**：`ExceptionWorkflowApiTest::test_director_confirming_candidate_voids_stale_pending_learning_record_on_original_session` 重現「原堂次已有 pending 評量」情境，確認 confirm-candidate 後該筆評量被正確作廢（`VoidReason` = `CourseLeaveCascadeService::VOID_REASON_LEAVE`）。

## 2026-08-06 — chore(hardening): R98/R99 根本解決＋防再犯（大公司作法對照）

- **背景**：R98（主任視角 schedules 永遠回傳空陣列）與 R99（同名重複老師帳號讓課程消失）修復上線後，CEO 要求不能只是修好眼前的 bug，必須參考大公司軟體從根本解決、避免同類問題再發生。這是針對兩者的「防再犯」加固，本身不改變任何使用者可見行為。
- **R98 防再犯**：`buildStudentClassesApiUrl()` 與 `buildSchedulesApiUrl()` 原本各自 inline 一份「依角色決定要不要帶某個過濾參數」的判斷，這正是為什麼其中一份會被手滑寫反而沒被發現。抽出單一函式 `resolveCalendarRoleScopeParams()` 作為兩個端點共用的唯一真相來源——這是業界處理「同一份授權/範圍邏輯不能有兩份互相對照的拷貝」的標準作法（類比 Django REST Framework 的 `get_queryset()`、Rails Pundit/CanCanCan 的集中式 authorization scope：範圍邏輯只能有一個出處，不能讓每個呼叫端各自决定）。
- **R99 根本解決（不只是防禦性修法）**：前一版修法（`teacherAliasMatch.js`）是前端防禦——即使有重複帳號，UI 也不會再讓課程憑空消失。但真正的根因是「系統裡允許存在兩個帳號、同一個顯示名稱」，這本身就是資料品質問題，光靠前端補洞治標不治本。參考大公司作法（Salesforce「合併帳戶/聯絡人」、HubSpot 聯絡人去重、Stripe customer merge：偵測到後直接把兩筆記錄合而為一，所有關聯 FK 一次改點到正確的那筆，不是讓每個消費端各自兼容兩個 ID）：
  - 新增 `GET /api/v1/admin/teachers/duplicates`（super_admin）：掃描所有老師帳號，列出顯示名稱重複的群組，附上各自的堂數/課程數，方便主動盤點。
  - 新增 `POST /api/v1/admin/teachers/merge-preview` 與 `POST /api/v1/admin/teachers/merge`（super_admin，需帶 `confirm: true`）：把既有、已測試過的 `TeacherUserMergeService`（原本只能透過 `php artisan teachers:merge-users` 在伺服器上執行）包成 HTTP 端點——不需要 SSH 進 Pi 就能安全執行帳號合併，所有寫入仍然走同一套經審查、有測試的 service。
  - 新增建立老師帳號時的重複姓名檢查（`ProfileController::store()`）：偵測到同名的既有在職老師帳號時預設擋下（409 `DUPLICATE_TEACHER_NAME`），需明確帶 `allow_duplicate_name=true` 才能建立第二個帳號——把「這是刻意的」變成一個明確的選擇，而不是資料輸入意外。
- **另一個發現：我自己這次資料修復造成的評量記錄遺失，其實系統已經有審計基礎設施，只是被繞過了**——`maybeRebuildSessionsAfterUpdate()` 的整批重建路徑用 `Model::where(...)->delete()`（query builder 批次刪除），這種寫法不會觸發 Eloquent model events，導致既有的 `ScheduleAuditLog`／`ClassSessionObserver::deleted()` 完全沒有記錄到刪除，資料一旦被覆蓋就真的無跡可尋（這正是稍早試聽課評量內容遺失、無法還原的原因）。修法：`LearningRecord` 在刪除前先寫入 `ScheduleAuditLog` 快照；`ClassSession` 改成逐筆 `->delete()`（讓既有 observer 正常觸發），而不是新增一套獨立機制——重用系統既有的、已經在其他地方運作的審計基礎設施，而不是每次遇到問題就發明一套新的。
- **測試**：`teacherAliasMatch` 相關單元測試維持；新增 `TeacherDuplicateControllerTest`（duplicates 列表、merge-preview、merge 需 confirm、拒絕非老師帳號）、`RebuildDestructiveDeleteAuditTest`（驗證整批重建前會先把被刪除的評量內容寫進審計記錄，且刪除堂次本身也會留下記錄）、`ProfileStoreTeacherTest` 新增重複姓名擋下與明確覆蓋兩種情境；`calendarCourseLoad.test.js` 新增 `resolveCalendarRoleScopeParams` 單元測試。`--filter='Course|Session|Materiali'`（588 tests）、`--filter='Teacher|Merge|Schedule|Rebuild'`（482 tests）、`--filter='Profile|Teacher'`（257 tests）皆綠燈。
- **後續**：上線後將透過新的 `merge` 端點，把「高為澎」的重複帳號（ID 260，此次 in-app #219/#223 的根因）正式合併進主帳號（ID 73），從資料層徹底解決，而不是只靠前端 alias 邏輯兜底。
- **關於稍早遺失的評量內容（吳宥萱 6/18 試聽）**：確認系統對 `LearningRecord` 內容沒有任何備份或歷史版本機制，且無法聯繫到當事老師本人核實，CEO 已核准結案不再追查——這筆內容視為不可挽回，堂數與收費不受影響。

## 2026-08-06 — fix(calendar): 同一位老師掛兩個帳號時，「輸家」帳號的課程在日檢視消失

- **背景**：schedules teacher_id 洩漏 bug 修復並上線後，回報者（鄭宇志）新提交 in-app #223（含截圖），確認 6/17、6/18 高為澎老師的試聽學生（吳宥萱）依然沒有顯示在課表上——但同一天同一位老師底下其他學生的課程都正常顯示。這代表問題不是「整層資料消失」，而是更精準地卡在某一筆特定課程。
- **根因**：查詢 `/api/v1/student-classes` 確認該筆課程（ID 3153）本身資料正確、後端也正確回傳；問題出在前端 `SmartCalendar.vue`——「高為澎」在系統裡其實掛了兩個帳號（ID 73，實際上課用的主帳號；ID 260，幾乎沒用過的重複帳號），`filterTeacherOptions` 會把顯示名稱相同的帳號合併成一欄（`alias_ids`），欄位代表 ID 取課程數較多的那個（73）。但日檢視實際渲染課程的 `getCoursesForTeacherAt()` / `getSlotOccupancy()` 只比對 `course.teacher_id === 合併後代表 ID`，從未比對別名帳號集合——課程 3153 的 `teacher_id` 是 260（輸家帳號），永遠比對不到欄位代表 ID 73，於是直接消失，即使欄位本身確實叫「高為澎」也一樣。同一支檔案裡週檢視篩選老師 chip 用的 `weekViewExpandedTeacherIdSet` 其實已經正確處理別名展開，只是日檢視這兩處被遺漏。
- **修法**：抽出 `frontend/src/lib/teacherAliasMatch.js`（`resolveTeacherAliasIds` / `courseBelongsToTeacherAlias`），`getCoursesForTeacherAt()`、`getSlotOccupancy()`、`visibleTeachers` 內的排序用 `teacherHasCourseToday()` 都改為比對別名帳號集合，與週檢視既有的正確邏輯一致。
- **測試**：新增 `teacherAliasMatch.test.js`，鎖住「課程掛在合併後的輸家帳號仍要能命中該欄位」；`npm run test:calendar` 全綠。
- **記錄**：`docs/AI_REGRESSION_LESSONS.md`（新增條目）。

## 2026-08-06 — fix(calendar): 主任／管理員角色的行事曆「schedules」層永遠回傳空陣列

- **背景**：in-app #219（鄭宇志回報試聽課不顯示）修復後，回報者反映問題仍存在。用真實主任帳號（非 Super Admin）實測發現：週檢視整週 0 堂課，即使載入進度顯示「112/112」項目都抓到了。
- **根因**：`frontend/src/lib/calendarCourseLoad.js` 的 `buildSchedulesApiUrl()` 條件寫反——非老師角色（主任／管理員）呼叫 `/api/v1/schedules` 時，把自己的登入者 ID 當成 `teacher_id` 帶進去（`!isTeacher && userId` 應該是 `isTeacher && userId`，比對同檔案 `buildStudentClassesApiUrl()` 的正確寫法）。後端 `ScheduleController::index()` 對 `teacher_id` 是無條件套用 `where()`，不分角色；主任自己的 user ID 不可能等於任何老師的 ID，於是 schedules 這層永遠查到空陣列。行事曆週檢視需要 `schedules`（模板／例外層）+ `class-sessions`（已物化層）合併才是完整資料（G-007），只要某筆課程當週只活在 schedules 模板還沒物化，主任視角就會直接看不到——這正是「主任改不過去、CEO 改得過去」的根本原因，也比 in-app #219 原本回報的單一個案範圍更大。
- **修法**：`calendarCourseLoad.js` 與 `useCalendarDataLoad.js`（legacy fallback 路徑同一個 bug）的 `teacher_id` 判斷條件改為 `isTeacher && userId`，與 `student-classes` 端點的既有正確邏輯一致。
- **測試**：`calendarCourseLoad.test.js` 新增回歸測試，鎖住「主任/管理員視角的 schedules URL 絕不能帶 teacher_id」；`npm run test:calendar` 全綠。
- **記錄**：`docs/AI_REGRESSION_LESSONS.md`（新增條目）。

## 2026-08-06 — fix(ux): 課程單堂操作「調課」與「備註 / 時段」按鈕文案釐清

- **背景**：主任回報（興隆分校）學生課程「星期六改星期四」改不過去，CEO 自己操作卻成功。查證後發現後端行為一致，差異在使用者點了哪個按鈕——「調課」可換日期，「備註 / 時段」物理上不能換日期（PATCH 驗證規則沒有 `session_date` 欄位），但兩個按鈕視覺相近、命名都圍繞「時間／時段」，沒有任何線索區分。
- **修法**：純文案／視覺調整，不改後端行為——按鈕文字明確化（「調課」→「🔄 調課（換日期）」；「備註 / 時段」→「備註 / 當天時段」）、各自加 tooltip、選單下方加指引文字、「調課」改用品牌主色系避免視覺上輸給「備註 / 時段」。
- **測試**：新增 `SessionEditModal.test.js`，鎖住兩按鈕文字互斥、tooltip 內容、選單提示存在。
- **文件**：`docs/SYSTEM_TECH_GUIDE.md` §13（業界對照：Google Calendar/Calendly 單一入口處理日期+時間、Nielsen Norman「Recognition rather than recall」原則）、`docs/AI_REGRESSION_LESSONS.md` R97。

## 2026-08-06 — fix(scheduling): 課程管理頁面「預排」日期在無歷史堂次的課程完全不顯示

- **背景**：主任回報（in-app #222，陳依娟／興隆分校）「為何預排只能打一個」——課程列表裡大多數課程完全沒有「預排」日期，只有剛好有堂次紀錄的那一門有。
- **根因**：`ClassSessionController::buildProjectedByClassForIndex()` 把「已計算的預排候選課程清單」直接等於「目前查詢範圍內已有實體堂次紀錄的課程」，沒有歷史堂次紀錄的課程（例如剛排好、第一堂還沒到的新課程）完全不會被納入預排計算，不論排課星期/時段本該投影出幾筆未來日期。
- **修法**：候選課程清單改為「已有歷史堂次的課程」聯集「請求明確帶入的 `student_class_id`/`student_class_ids`」（課程管理頁面批次載入時一定會帶入畫面顯示的所有課程 ID）。
- **測試**：新增 `SessionProjectionSplitTest::test_class_sessions_index_projects_course_with_no_materialized_rows_in_range`，修復前 RED、修復後 GREEN；`Course|Session|Materiali` 廣義掃描（565 tests）維持綠燈。

## 2026-08-06 — docs+chore(billing): 「已繳費」判斷全專案盤點，收斂 AlertController 內部重複，TD-073 調升 P1

- **背景**：使用者要求何昀佳繳費狀態案不能只點修，還要對照大公司軟體看核心問題、從根本解決。
- **盤點結果**：`backend/app` 至少 8 個檔案（`StudentClassController`、`AlertController` 內部另兩處、`NotificationSyncService`、`DunningService`、`PaymentReportController`、`ParentPortalController`、`NotificationController`、`AccountingController`、`SendTuitionReminders`）各自獨立重新判斷「這筆課程是否已繳費」，至少 4 種互不相同的變體並存；`StudentClass`／`Invoice` model 完全沒有集中的 `isPaid()` 存取器。這是 `TD-073`（重複業務邏輯無自動偵測）論點同一天第三次驗證。
- **本次收斂範圍**：`AlertController.php` 內部原本重複兩次的同一組條件（`computePaymentStatus()`、`computePackageCountPaymentStatus()`）抽成單一私有方法 `isFullyPaid()`；**未**跨檔案大改其餘 8 處——那需要逐一取得產品方核准（`DunningService.php` 已被明文凍結），列為 TD-073 子項待後續分批清償。
- **文件**：`docs/SYSTEM_TECH_GUIDE.md` §12.5（新增，業界對照：Stripe `Invoice.status`／Shopify `Order.financial_status` 單一權威實作模式）、`docs/TECH_DEBT.md` TD-073（P2→P1）、`docs/AI_REGRESSION_LESSONS.md` R95（新增）。

## 2026-08-06 — fix(billing): 帳務中心繳費狀態未計入帳單足額收款（F7 復發）

- **背景**：主任回報某學生課程已用帳單收款紀錄結清，課程管理頁面正確顯示「已繳費」，帳務中心卻仍列「未繳費」。已取得產品方（`docs/DIRECTOR_PAYMENT_ALERT_RULES.md` 明文要求的核准）同意修改後動手。
- **根因**：`AlertController::computePaymentStatus()` 只看 `StudentClass.Paid` 欄位，沒有把帳單足額收款（`paid_amount >= charge`）算進「已繳費」判斷，跟課程管理頁面的邏輯不同步——`docs/AI_REGRESSION_LESSONS.md` **F7「繳費金額/狀態雙真相」** 家族的又一個成員。
- **修法**：`$isPaid = Paid=1 或 (charge > 0 且 paid_amount >= charge)`；刻意用「足額」而非「有任一筆收款」判斷，避免連帶把 `partial`（部分付款）狀態也誤判為已繳。
- **測試**：新增 2 個回歸測試涵蓋「帳單足額結清但未切 Paid 旗標」情境（含剩餘 0 堂的續課提醒分支）；既有 `TuitionAlertsApiTest`／`LargeBranchDataHandlingTest` 全數維持綠燈。
- **文件**：`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`、`docs/AI_REGRESSION_LESSONS.md` R94 已同步更新，依規則要求。

## 2026-08-06 — docs: 補當天 6 個 bug 的更深層根因分析，登記 TD-073

- **背景**：使用者要求不只記「修了什麼」，還要對照大公司軟體工程作法，回答「為什麼這類問題在成熟組織比較少見」這個更根本的問題。
- **新增內容**：`docs/SYSTEM_TECH_GUIDE.md` §12.4——結論是大公司工程師並不會少寫重複邏輯/magic string，差異在「有沒有東西在合併前把它攔下來」：強制第二人 code review、自動化重複程式碼偵測（CI 合併門檻而非文件建議）、明確的領域歸屬。本專案目前三層都不完整（單人 repo、PHPStan 不抓語意重複、無領域 owner），且相當比例的變更由彼此無上下文延續的 AI agent session 完成，進一步放大這個結構性缺口。
- **登記技術債**：`docs/TECH_DEBT.md` TD-073——CI 缺少自動偵測重複業務邏輯/重複 magic string 的機制，建議分階段導入 `phpcpd` 與輕量 grep-based magic-string presubmit 檢查。

## 2026-08-06 — chore(hardening): 請假 VoidReason 業務字串收斂成單一常數，並補業界作法對照文件

- **背景**：回顧當天修的 6 個 in-app bug 與陳禹慈堂數超排案，發現全部落在同一組業界有名字的反模式下（授權範圍判斷分散、業務字串未收斂、狀態機不對稱、集合資料整包覆蓋、同一規則重複實作）。詳見 `docs/SYSTEM_TECH_GUIDE.md` 第 12 節逐一對照與加固守則。
- **本則實際變更**：`一般請假`（撤銷請假比對用的 VoidReason 字面值，#217/#218 就是這個字串的其中一份被打壞才炸掉）在 `CourseLeaveCascadeService.php` 裡以字面值重複寫了 6 次，`LearningRecordResurrectionPolicy.php` 的系統可復活白名單又各自抄了一份。新增 `CourseLeaveCascadeService::VOID_REASON_LEAVE` 常數，7 處使用點全部改成引用同一個常數，未來只可能改壞一份、也只需要改一處。
- **測試**：leave/session/attendance/schedule/learning-record 相關 572 個測試、2415 個 assertions 全綠；PHPStan 對兩個變更檔案無新增錯誤。

## 2026-08-06 — fix(scheduling): 請假/取消自動補堂改為單一權威實作，避免堂次數超過已購堂數

- **背景**：新店分校主任回報，一名學生（英文課）明明購買 8 堂，繳費單卻顯示 12 堂、日期不連貫，系統回報「超排」，老師已先手動取消部分堂次暫時排除。
- **根因**：「請假/取消後自動於課表尾端補一堂」這件事，程式碼裡各自獨立寫了兩份——`CourseLeaveCascadeService::appendTailAfterLeave()`（`/schedules/leave-by-session`、`/schedules/retro-leave` 用）與 `ClassSessionController::tryExtendOnLeave()`（`PATCH /class-sessions/{id}` 用）。兩份對「已計入堂數」的認定不完全一致，且互不知道對方存在，交替經由不同入口對同一堂課操作請假/取消，會讓非取消堂次數悄悄超過實際購買堂數，最終撞上系統自己的超排防呆。
- **修法**：刪掉 `ClassSessionController` 那份重複實作，改為呼叫唯一權威的 `CourseLeaveCascadeService::appendTailAfterLeave()`；順手把原本只存在於 Controller 那份的「暫停中課程（Stop=1）不補堂」防呆，一併搬進共用服務，讓所有入口都受到保護。
- **測試**：新增 `LeaveCascadeSingleAuthorityTest`，模擬同一堂課交替經由兩個入口請假/取消，斷言非取消堂次數全程不超過已購堂數；另補暫停課程不補堂的回歸測試。既有 leave-cascade 相關測試（`ScheduleLeaveCascadeTest`、`LeaveKeepDatesAppendTailTest` 等）全數維持綠燈，PHPStan 全域無新增錯誤。

## 2026-08-05 — chore(dashboard): 清理 DirectorDashboard.vue 死碼（PR #1515 work-grid + Wave A/B + 舊版 workbench，全部完成）

- **背景**：稽核 PR #1515（Wave B, AtCard/AtEmpty/AtMetric work-grid）的「缺測試」警告時發現，該 PR 改的整段標記早已因後續 `director-workbench-v2` 改版而被 `<template v-if="false">` 永久包住、完全打不到；同一個 `v-if="false"` 區塊內還疊了更早期的 action-lane、progress-board、第一版 workbench，以及一個已停用的匯入格式說明 modal。因單一 PR 改動 1,100+ 行會撞到 CI 的 700 行 PR-size 上限，拆成兩個 PR 分批清除（part 1：`chore/dashboard-deadcode-part1`；part 2：本則）。
- **清掉的東西**：整段死碼標記——舊版 workbench v1（今日待辦佇列＋今日快照＋trust-summary）、重複的 E-OPS-TRUST decision center、action-lane、progress-board、work-toolbar 檢視切換、Wave B work-grid（`AtCard`/`AtEmpty`/`AtSkeleton`/`AtMetric`）、kpi 科目數統計區塊、一個死掉的匯入格式說明 modal、一張重複的「尚無分校資料」卡片；連帶清掉只服務這些標記的 JS——engagement rank strip 整套（snapshot／顯示開關／reduced-motion／visibility 監聽，只服務已刪除的舊 header）、CSV 匯入 state 與 handler、老師評量填寫率與科目數統計 API 呼叫（原本每次切到「完整營運」都會打，資料卻從未顯示在任何畫面）、`AtCard`/`AtEmpty`/`AtSkeleton`/`AtMetric` 元件 import（Wave B 專用、從未在真正頁面渲染過）。檔案從 4,023 行減到 2,928 行。
- **沒動的東西**：現在真正在跑的 `director-workbench-v2`（今天／完整營運兩個檢視、`#schedule-sec`/`#evals-sec`/`#payments-sec` 等 `surface-panel` 卡片）完全沒有改動邏輯，只刪除同檔案內已經打不到的舊版本。
- **驗證**：`vite build` 全綠；`no-undef` ESLint 過；補的 e2e（`director 完整營運 cards ...`，涵蓋 empty state 與有資料時的筆數／badge）與既有 12 個 `director workbench` 系列共 120 個 ui-foundation e2e 全過。
- **順帶修復**：處理 part 2 時被 Presubmit CHECK 2 的 700 行上限擋下（part 1 分批後仍是 1,095 行純刪除），發現這個 gate 用同一把尺量「新增邏輯」和「刪除已證明打不到的死碼」並不合理，補了窄範圍例外（insertions ≤30 且 deletions 遠大於一般上限時，改用 deletions ≤3000 的上限），見 `.github/workflows/presubmit.yml` CHECK 2 與 `.cursor/rules/module-industry-standards.mdc`。

## 2026-08-02 — feat(payroll): 兼職薪資改依實際到班點名計算，鎖定後產生不可變快照（補記錄，PR #1624 合併時漏寫）

- **本則為事後補寫**：PR #1624（`feat/parent-leave-approval` 分支，實際內容為兼職薪資重構，分支命名與內容不符）已於合併並自動部署到正式環境，但當時未依專案慣例更新本檔案；本則依實際 diff／PR 說明／正式環境資料庫驗證後補上，事後未變更任何程式碼。
- **核心改變**：兼職薪資計算來源從「已核准的學習評量（LearningRecord）」改為「有效到班點名（`ClassSession` + `StudentSingIn`，經 `AttendanceStatus::payableCodes()` 判斷可計薪狀態）」。評量是否核准或作廢不再影響該堂課是否計入薪資；計算工時與併班加給所用的時段，也改用點名時段而非評量清空時的時間。
- **鎖定＝不可變快照**：主任「鎖定」某月薪資時，後端先驗證出勤異常（缺老師／缺時段、已取消但仍有點名、狀態不一致、重複點名等），任一異常存在即回 422 並拒絕鎖定；驗證通過才寫入 `payroll_runs`／`payroll_run_lines`（新表，migration `2026_08_02_000300_create_payroll_runs_and_lines.php`）快照，並把 `payroll_month_status.current_run_id` 指向該次快照。鎖定期間，薪資總表、老師明細與 CSV 匯出一律讀快照；「重新開啟」須填原因，快照標記 `reopened`，月份退回草稿。
- **API／畫面**：薪資總表回應新增 `payroll_basis: attendance`、`anomaly_count`、`anomalies`；`ParttimePayrollPage.vue` 說明改用出勤計薪、列出異常清單、異常未清空前鎖定鈕停用、鎖定後顯示快照橫幅；`parttimePayrollApi.js` 的鎖定錯誤處理會帶出異常明細。
- **測試**：PR 內 focused PHPUnit 66 個測試、212 個 assertions，涵蓋既有 `PayrollConcurrencyTest`／`PayrollRateConsistencyTest`／`PayrollRulesTest`／`PayrollTeacherOverrideTest`／`ParttimePayrollTest` 回歸與新的鎖定／重開流程；前端 build 通過。本則補記錄時另以正式環境唯讀 SQL 確認 `payroll_runs`／`payroll_run_lines`／`payroll_month_status.current_run_id` 已建立且與 migration 定義一致。
- **風險**：PR 自評為 High Risk（薪資金額與月結鎖定），涉及教師實際收入計算方式變更；本則補記錄不代表已完成獨立覆核，僅還原「改了什麼、為何改、怎麼驗證」，供後續稽核與變更追蹤使用。

## 2026-08-02 — feat: 老師每日工作流精簡為單一待完成佇列（補記錄，PR #1619/#1620 合併時漏寫）

- **本則為事後補寫**：PR #1619（`feat/ux-teacher-daily-v2`）已合併並部署，後續 PR #1620 只補了 `docs/MODULE_TEACHER_DAILY_WORKFLOW_UX.md` 設計規格文件，兩者都未依專案慣例更新本檔案；本則依該規格文件、實際 diff（`git show 19ebf7cd`）補上，未變更任何程式碼。Issue #1618。
- **問題**：`TeacherHomePage` 原本同時顯示多個工作中心、KPI 卡片、分析、進度、排名與導覽區塊，老師要比對好幾份重疊清單才知道下一步；`AttendancePage` 也是先看統計數字而非「現在該做什麼」。重複計數、多個主要 CTA 互相競爭，且有請假狀態被誤放進工作清單的風險。
- **設計**：老師工作台第一眼改成單一待完成佇列「今天要完成」，每個項目固定有類型、狀態、對象（學生／課程／時間）、期限與**恰好一個**主要動作（待點名→開始點名、缺評量→填寫評量、需修改→修改評量、家長回覆→查看並回覆）。排序為「需修改／逾期 → 今日未完成 → 回饋」，同一評量記錄／堂次去重。`leave`／`leave_requested`／`leave_adjusted`／`excused` 這組請假狀態一律不進入點名或評量待辦佇列。進度、回饋分析、排名、SystemTrust、聊天未讀移到次要區塊，不擋主佇列；`AttendancePage` 首屏改成「先完成今日點名」單一入口。
- **邊界**：未動資料庫、API、權限、出勤或請假／評量資料契約；純畫面工作佇列與請假排除邏輯集中在新檔 `frontend/src/lib/teacherDailyWorkflow.js`（含純邏輯回歸測試）。
- **測試**：純邏輯測試涵蓋排序、去重、CTA 對應、空清單、請假排除；real Vue Playwright 涵蓋 390／412／1280px 的 normal、empty、行事曆 API error、手機出勤 CTA、次要分析延遲載入、無非預期水平溢出；測試用日期改用瀏覽器本地時間推算，避免 CI 時區飄移。`lint:no-undef`、design token guard、Vite build 與既有回歸全部通過。
- **回滾**：revert PR #1619 合併 commit 即可；無 schema migration 或資料回填。

## 2026-08-02 — fix: 主任已核准/退回的請假案件不再因深連結重新出現於待處理佇列

- in-app #215／GitHub #1625：主任經通知深連結核准或退回請假案件後，畫面會把已結案的案件重新插回待處理清單，造成「處理完卻卡住沒消失」，再按一次核准則被系統擋下「已結案」。
- 根因為前端重新抓取深連結指定案件時未檢查其狀態即塞回清單，純屬畫面重複顯示問題，後端狀態與資料未受影響。
- 新增 `exceptionWorkflowFocus.js` 作為待處理狀態集合的唯一判斷依據，`DirectorDashboard.vue`／`CourseManagement.vue` 兩處清單過濾與深連結重新插入邏輯改為共用同一份判斷，避免未來兩處再度各自為政。
- 新增回歸測試鎖定：已結案（`waived`/`confirmed`/`rejected`）案件經深連結重新抓取後不得再度出現在待處理清單。

## 2026-08-01 — feat(learning): 學習評量表新增多筆內容預覽

- 老師預設可在列表／卡片直接掃讀作業狀態、週考、上課狀況、授課進度、作業範圍與家長溝通，不需逐筆開啟編輯表單。
- 桌面採資料列下方 full-width read-only preview；390／412px 手機強制使用卡片預覽，主要 CTA 不藏在水平捲動表格後面。
- 主任可用「預覽內容」開關查看同一份只讀摘要；編輯、核准、需修改、退回與既有 API／權限契約不變。
- 新增 preview projection 單元測試、real Vue Playwright 五寬度驗收與設計研究紀錄 `docs/MODULE_LEARNING_RECORDS_UX.md`。

## 2026-08-01 — feat(ux): 行事曆與調課工作流明確化

- 第二個 AllTrue UX Renewal bounded context：建立 `MODULE_CALENDAR_SCHEDULE_UX.md`，以 Google Calendar 的日期範圍／Today、Outlook Scheduling Assistant 的衝突導向與 FullCalendar 的事件互動模型為研究基準，Issue #1605 追蹤交付。
- `SmartCalendar.vue` 現在固定呈現目前檢視、可見日期範圍、堂數與「回到今天」；保留既有 `calendarOccurrenceMerge.js`、日期語意與拖曳／調課寫入契約，不改 API、權限或資料真相。
- 課表回報頁的案件動作改成依狀態表達「接手處理／繼續處理／查看處理結果」，補上 tabpanel、展開狀態與行動版明細 ARIA；回報類型由超大型膠囊改為低裝飾標籤，降低 pill 堆疊與 AI 生成感。
- 課表 API 空回應與失敗分開呈現，補上錯誤提示與重試；行事曆與課表回報均以真實 Vue page 驗收 normal、empty、loading、error、long text，390／412／768／1280／1440px，所有情境 `scrollWidth <= clientWidth`。
- 純邏輯、既有 calendar 回歸、lint、design token guard、Vite build 與 Playwright 全部通過；本次不修改資料庫、堂數、請假／調課 atomic boundary 或既有權限規則。

## 2026-08-01 — feat(ux): 課程管理明確呈現家長請假案件與主任 deep-link

- 第一個 AllTrue UX Renewal bounded context：新增全站 roadmap 與課程／家長請假 PRD，建立 Epic #1600 與模組 Issue #1601；研究企業 dashboard、Jira workflow、Primer、Filament、Chatwoot、Gibbon 與 starred repos 後，採「案件摘要 → 明確動詞 CTA → 指定案件處理」結構。
- `CourseManagement.vue` 不再只顯示「請到主任收件匣」通知；現在列出學生、原堂次、原因、狀態與「處理這筆請假」，可直接帶 workflow ID 導向主任 `exception-workflows` 區段。保留既有安排補課／核准不補課／退回 API 與資料真相。
- `App.vue` 新增課程管理導覽 adapter，兼容既有字串頁面導覽與 workflow deep-link；同時修正課程學生群組巢狀 button，改為可 focus、可 Enter/Space 操作的群組標頭。
- 新增純邏輯測試與 real Vue Playwright evidence：normal、empty、loading、error、long text；390／1280px；CTA、deep-link payload 與 `scrollWidth <= clientWidth` 驗收通過。`lint:no-undef`、design token guard、Vite build 與既有回歸測試通過。
- 本次不修改資料庫、權限規則、堂數計算或 Charge/Invoice/Payment truth；完整 production deploy evidence 仍待 PR/CI/merge 後完成。

## 2026-07-30 — fix(security): StudentClassController::togglePause() 跨分校／跨老師授權缺失（P0 containment）

- 治理稽核發現 `togglePause()`（`/student-classes/{id}/pause`，暫停／恢復課程）在任何 object-level authorization 之前就直接讀取並修改 `StudentClass.Stop`／`closed_reason`／`EndDate`，並連動取消未來 `ClassSession`，與 #1504/#1509（`confirmPayment()`）同一類跨分校 IDOR，尚未修補。任一分校 director／teacher 帳號可用他校 `StudentClassID` 暫停或恢復不屬於自己的課程。
- 修法：比照 #1509 的模式，在方法最前面呼叫既有的 `authorizeStudentClassAccess()`（未通過即回 403，不執行任何 mutation）。未新增授權邏輯、未變更既有 campus／teacher 判定語意。
- 新增 `backend/tests/Feature/StudentClassTogglePauseAuthzTest.php`（8 案例：跨分校 director、非本人課程 teacher 各自 pause／resume 應 403 且完全不改動資料；同分校 director、擁有該課程的 teacher 應維持原有 pause／resume 成功行為與未來排課取消語意）。修補前 4 個跨分校/跨老師案例可重現性失敗（RED），修補後全數通過（GREEN），既有 `StudentClassCloseFutureSessionsTest`／`StudentClassConfirmPaymentAuthzTest` 無回歸，全庫 PHPUnit 1580 測試全過。
- 本次僅做 containment（單一方法補授權檢查），未建立新的 CI gate、未重構授權層、未觸碰 #1062 排程或帳務邏輯。CI 綠後仍需 Founder 過目才可 merge（R2 風險等級：授權／跨分校邊界／課程狀態變更）。

## 2026-07-31 — feat(billing): 依實際上課時長扣堂——正式環境後端＋前端旗標已啟用（經 Founder 明確授權，未有課程走完驗收）

- 延續上一則（2026-07-31，功能合併但旗標關閉）。本則記錄：Founder 明確授權透過新的 Founder-gated GitHub Actions control plane（`.github/workflows/actual-duration-activation.yml`）進行受控啟用。
- 唯讀盤點（`inventory` action，run 30629251402）：1800 門課程掃描，20 門課程（37 堂）有「排課時長與該課程自己的契約時長不符」的**歷史**落差（全部是 happened，沒有 planned），與本次啟用無關，屬既有技術債。
- 後端旗標 `PERF_ACTUAL_DURATION_DEDUCTION`：`enable_backend`（run 30629298501）對照 production HEAD `dc88926e`，備份 `.env`（含 checksum）、單行 idempotent 修改、`optimize` 重建快取（非 `config:clear`）、**effective config 經 `php artisan tinker` 驗證為 `true`**（不只是看 `.env` 文字）、health `ok`、完整 authenticated smoke 通過、現有 1895 門課程確認仍全為 `fixed_session`。獨立 `verify_backend`（run 30629362904，非同一次執行）重新確認同一結果。
- 前端旗標 `ACTUAL_DURATION_DEDUCTION_ENABLED`（PR #1552）：merge 後 `deploy.yml` 自動部署（run 30629708223），`version.json hash=511ab1c7` 與 Pi git HEAD 一致，health + 完整 authenticated smoke 全通過。建課表單現在會顯示「扣堂方式」選項。
- **兩個旗標皆為 `true` 不等於「已完成驗收」**：沒有任何既有課程被動到，兩次獨立查詢皆確認全部課程仍是 `fixed_session`；只是「新建」課程時，授權角色現在可以選擇依實際時長換算，尚未有人實際走過這條路徑。
- 正式環境驗收案例（買 8 堂標準 120 分鐘、真實走 6 次 180 分鐘課程、驗證扣堂序列 780/600/420/240/60/0、超額不擋點名、扣堂後修改契約回 422）已備妥自動化 workflow（`.github/workflows/actual-duration-acceptance.yml`），但需要 Founder 指定安全的測試學生／老師／分校身分才能執行——目前沒有可重用的既有測試帳號可用（既有 smoke 帳號依政策僅限唯讀）。
- 回滾：`disable_backend` action（同一 workflow）為主要執行期回滾路徑，備份＋idempotent 修改＋驗證，不需要額外的 SHA 比對（避免緊急回滾被卡住）。
- 現況、稽核紀錄、下一步見 `docs/RUNBOOK_ACTUAL_DURATION_ACTIVATION.md`。

## 2026-07-31 — feat(billing): 每門課可自訂「標準一堂 = 幾分鐘」，依實際上課時長按比例扣堂（旗標關閉，尚未啟用）

- 起因：有學生每週上兩次、每次 3 小時，但課程的計價單位是 2 小時一堂。舊系統把「排幾次課」與「買了幾堂」綁死成同一個數字，導致「買 8 堂、只排 6 次 3 小時的課」這個需求在建課階段就被 422 擋掉，根本無法表達；就算硬排成 8 次，點名時每次也只會扣整整一堂，3 小時與 2 小時的課扣一樣多。
- 修法（RFC_NONSTANDARD_SESSION_DURATION_BILLING D1–D7）：`StudentClass` 新增 `standard_lesson_minutes`（nullable）與 `deduction_basis`（`fixed_session` / `actual_duration`，預設 `fixed_session`）兩個 additive 欄位。選擇 `actual_duration` 的課程，扣堂改為 `實際上課分鐘 ÷ 該課程的標準一堂分鐘`。
- **「一堂」是每門課自己的定義，不是全公司的規定**：同樣一次 180 分鐘的課，在標準 90 / 120 / 180 分鐘的課程裡分別扣 2.00 / 1.50 / 1.00 堂；買 8 堂分別等於 720 / 960 / 1440 分鐘。建課表單預填 120 只是輸入框的初值，授權老師／主任可自行改；後端沒有任何 fallback 到 120 的行為，也不會用這個數字重新解釋任何既有課程。
- D6 解耦：`session_plan` 決定排幾次課，`SessionCount` 決定買了多少額度，兩者不再被強制相等。系統不會為了讓數字對齊而自動刪除、縮短或截斷任何一次排課。`fixed_session` 課程的「次數需等於購買堂數」規則一字未改。
- D5 超額處理：排課時數超出已購額度時，建課 API 回 422 `overage_confirmation_required`，附上完整換算明細（額度分鐘、排課分鐘、可完整涵蓋幾次、第幾次會不夠、缺多少分鐘與幾堂），老師明確勾選確認後才能建立。**確認只發生在建課階段——課程建立後，超額永遠不會擋住學生被點名。**
- 分鐘是唯一權威的計費真相（沿用 #613 A1 的 `PurchasedMinutes` / `RemainingMinutes` / `session_deduction_ledger.minutes`）；堂數一律以整數運算產生固定小數位的**字串**呈現，binary float 不會成為任何計費數字。課程列表新增 `remaining_hours` / `remaining_lesson_equivalent` / `used_lesson_equivalent`；舊的整數 `remaining_sessions` 保留相容，但它無法表達「還剩半堂」，正是本次要消除的誤解來源。
- 第一筆扣堂 ledger 寫入後，`standard_lesson_minutes`、`deduction_basis`、`SessionCount` 由 `BillingContractLockGuard` 在**後端**鎖定（前端變灰只是 UX）。v1 刻意不提供任何扣堂後的契約修正管道——額度仍由 `SessionCount × standard_lesson_minutes` 推導，事後改標準堂長等於重新解釋歷史，宣稱「只影響未來」會是假保證；要改就結掉重開。
- v1 範圍外：跨期借用／自動拆堂（D3 不做）、共用課程包（D4 雙向排除，422 明確拒絕）、月結制（422 拒絕）、額度授予 ledger（D7 延後）。
- **Dark launch，兩個開關缺一不可**：`PERF_ACTUAL_DURATION_DEDUCTION`（環境）與 `StudentClass.deduction_basis`（每門課）；前端另有編譯期 `ACTUAL_DURATION_DEDUCTION_ENABLED`。三者預設皆為關。Fail-safe 而非 fail-open：環境旗標關閉時，已標記 `actual_duration` 的課程扣堂行為完全等同 `fixed_session`，因此關掉旗標就是完整回滾，沒有資料要遷移或清理。建課 API 同樣受環境旗標管制，旗標關閉時直接拒絕建立這類課程，避免出現「契約寫著按時長計費、實際卻整堂扣」的課。
- 另附唯讀盤點指令 `php artisan sessions:report-nonstandard-duration`（不寫任何資料，輸出開頭明示 `READ_ONLY=true`），供啟用前評估現存排課時長與計價單位不一致的規模；`--details` 只帶 ID，不輸出學生姓名／電話／RFID。
- 既有課程行為零變化：所有現存課程都是 `fixed_session`（欄位預設值），且旗標出廠即關。後端 Feature 全套 1554 測試、6581 assertions 全綠；本功能自身 100 個測試（後端 47＋純計算 53）＋前端 10 個；PHPStan 無新增錯誤、未動 baseline；migration 已實測可 rollback 再重跑。
- 端到端驗收（`ActualDurationEndToEndAcceptanceTest`）走真實 HTTP：建課 → 點名 6 次 → 讀餘額，驗證 `PurchasedMinutes = 8 × 120 = 960`（計費標準，不是排課的 180）、只物化 6 次、剩餘分鐘依序 780/600/420/240/60/0、額度歸零後仍可點名、課程列表能顯示 `4.25` 這種整數欄位表達不了的餘額。這個測試在撰寫時抓到一個真實缺陷：`PurchasedMinutes` 曾被算成 `8 × 排課時長 180 = 1440`，正是 D1「計費長度 ≠ 排課長度」要分開的東西。
- 上線步驟與回滾程序見 `docs/RUNBOOK_ACTUAL_DURATION_ACTIVATION.md`。**本次僅完成合併，尚未在正式環境執行任何部署、migration、旗標開啟或資料異動。**

## 2026-07-29 — fix(dashboard): 主任總覽頁面 Wave D —— 對照真實參考 repo 原始碼修正資訊密度與圓角一致性

- Wave A/B/C 完成後，實際 clone `RFC_PLATFORM_OPTIMIZATION_FROM_STARS_2026.md` 引用的 Epic D 參考 repo 原始碼（`pacifio/ui`、`primer/css`、`carbon-design-system/carbon`、`microsoft/fluentui`、`vbenjs/vue-vben-admin`），逐一讀真實檔案而非文件摘要，找出兩項可對照修正的落差：
  1. `pacifio/ui` 的 `kitchen-sink/app/patterns/dashboard/page.tsx`（真實 dashboard pattern 頁）明文規則「Lead with 4 key metrics max — any more dilutes attention」；`progress-board` 原本擺 6 個 `AtMetric`（今日到班／待審評量／今日應處理／今日已完成／已逾期／未讀通知）。改法：把「今日應處理／今日已完成／已逾期」三個同屬 workflow 彙總的數字合併成一個「今日工作量」`AtMetric`（`value`=應處理數，`delta`="已完成 N・逾期 N"），不砍任何資訊，只砍視覺格數，降到 4 格。
  2. `AtCard.vue`／`AtMetric.vue` 的 `border-radius: 12px` 是硬寫死值，未接上 AllTrue 自己在 `styles.css` 早就定義好的 `--ds-radius-sm/md/lg/pill`（4/6/8/9999px）token；`progress-board` 外框也是硬寫 `16px`（桌面密集模式甚至是 `22px`，比一般模式的圓角還大，違反「密集模式該更緊湊」的直覺）。`pacifio/ui` 的 `skills/atlas/references/lessons.md` 第 16 條「Radius is sparse on purpose」明確列出合法圓角只有 3／4／6／9999px，「Don't invent 8, 10, 12, 16px radii」——與 AllTrue 自己既有的 token 規模高度吻合，兩邊互相印證。改法：`AtCard`／`AtMetric`／`progress-board`（含桌面密集模式覆寫）全部改接 `var(--ds-radius-lg)`（8px），不新增 token。
  - 順手清掉 `dash--desktop-dense` 底下對應 Wave B 轉換後已無模板引用的 `.pb`／`.pb__val` 死 CSS（`.pb-cell` 是現用類別，`.pb` 不是）。
  - `AtCard`／`AtMetric` 目前僅 `DirectorDashboard.vue` 使用（已確認站內無其他頁面引用），圓角改動影響範圍受控，不需要額外頁面回歸。
  - 已用真實 Vue 元件（`pilot-mount.js` `page=director`）在 390／768／1440px 截圖驗證新版 4 格 progress-board 排版與圓角觀感；`vite build` 全綠。純樣式與資訊呈現調整，未改任何 API、繳費／審核／排課邏輯。

## 2026-07-29 — feat(dashboard): 主任總覽頁面 Wave C —— 版面明確分組「今日必辦」與「本週趨勢與紀錄」

- 延續 Wave A/B，處理改善計劃最後一項：work-grid 兩欄式版面原本沒有明確的資訊層級——右欄把永遠顯示的卡片（課表回報、流程追蹤、通知摘要）跟只有「完整檢視」才出現的卡片（近 7 天代課、近期操作履歷、老師評量填寫率）交錯排列，使用者切換核心/完整檢視時，卡片是「無聲」冒出來，看不出彼此的分類邏輯。
- 修法：在 work-grid 上方加「今日必辦」區塊標題（沿用「每日待辦」既有樣式），並把右欄重新排序——先群組所有永遠顯示的卡片，中間插入「本週趨勢與紀錄」分隔標題（只在完整檢視顯示），再放三張完整檢視限定卡片。純樣式與 DOM 順序調整，未改任何 v-if 條件、資料邏輯或互動行為。
- 已用真實 Vue 元件（`pilot-mount.js` `page=director`）＋mocked API，核心檢視／完整檢視兩種模式在 390／1440px 截圖驗證；`npx vitest run` 172 個測試全過；`vite build` 全綠。
- 至此 DirectorDashboard 改善計劃三個 wave（收斂重複資訊源、At* 元件統一卡片殼、版面分組）全數完成。

## 2026-07-29 — fix(deploy): 圖示字型改走 Vite 資產管線自動雜湊（徹底解決快取殘留 + 部署白名單問題）

- 上一版 `PUBLIC_DIRS` 補 `fonts` 的修法（見下一則）部署後，使用者實測手機 PWA 仍持續顯示英文；伺服端直接 `curl` 驗證 `/fonts/material-symbols-outlined.woff2` 確實回 200 且內容正確，但用戶端仍壞——根因是這個路徑**沒有內容雜湊**，任何用戶端／CDN 邊緣節點若曾快取過舊的失敗回應（404 或載入失敗狀態），修復後同一個 URL 無法自動讓已快取的用戶端重新抓取，尤其手機「加到主畫面」的 PWA 有獨立於一般瀏覽器分頁的快取分區，一般「強制重新整理」也不保證清除。
- 修法：把字型檔從 `frontend/public/fonts/`（未經處理的靜態直通）搬進 `frontend/src/assets/fonts/`，`@font-face` 改用相對路徑 `url('./assets/fonts/...')` 讓 Vite 建置時自動產生內容雜湊檔名（如 `material-symbols-outlined-D6tU34w1.woff2`），跟其餘所有 JS/CSS bundle 一樣天然免疫快取——內容不變網址就不變，內容一變網址自動換新，不需要仰賴任何人記得加白名單或事後清 CDN 快取。連帶好處：不再需要 `copy-to-backend.cjs` 的 `PUBLIC_DIRS` 特殊處理，直接搭已存在、可靠的 `assets/` 複製流程。
- 已用真實 Vue 元件 + headless 瀏覽器驗證 `document.fonts` 狀態為 `loaded`、實際截圖確認圖示正確渲染；`npx vitest run` 全數 172 個測試通過；`vite build` 全綠。

## 2026-07-29 — fix(deploy): 修正正式站從未部署自架圖示字型（全站圖示曾顯示英文）

- 使用者反映「到處都是英文」。根因：#1512 把 Material Symbols Outlined 圖示字型改為自架（`frontend/public/fonts/`），但正式站部署實際執行的 `frontend/scripts/copy-to-backend.cjs`（`deploy.yml` SSH 到 Pi 後 `npm run deploy` 呼叫）的 `PUBLIC_DIRS` 白名單只有 `['audio']`，從未包含 `fonts`。導致 `/fonts/material-symbols-outlined.woff2` 從 #1512 合併後每次部署都在正式站 404，全站圖示靜默退回顯示英文原名。
- #1512／#1514／#1515 落地時的驗證路徑（Playwright ui-foundation 測試、`vite build`）都直接讀 `frontend/public/` 或 `dist_build/`，不經過這支部署時才跑的複製腳本，三次都沒抓到這個落差。
- 修法：`PUBLIC_DIRS` 加入 `'fonts'`。已實際執行複製腳本對照 `dist_build/` 輸出，確認 `backend/public/fonts/material-symbols-outlined.woff2` 正確產生。純部署管線修正，無業務邏輯變更。

## 2026-07-29 — feat(dashboard): 主任總覽頁面 Wave B —— 工作區卡片統一改用 At* 設計系統元件

- 延續 Wave A 的收斂整理，這次處理 `docs/design/UI_AUDIT_2026-07-26.md` 標記的「Metric/card density uneven」：work-grid 內 7 張卡片（今日課表、繳費／續課提醒、待審核評量、流程追蹤、近期操作履歷、老師評量填寫率、通知摘要）原本各自手刻 header／empty／loading 標記，密度與樣式略有差異。改為統一套用 `AtCard`（卡片殼＋header/actions slot）、`AtEmpty`（空狀態）、`AtSkeleton`（loading 骨架屏），`progress-board` 的 6 個統計 pill 改用 `AtMetric`。
- `AtCard`／`AtMetric` 先前只有元件層級單元測試，尚未在任何真實頁面接入；本次是它們第一次進正式頁面，已用真實 Vue 元件（`pilot-mount.js` `page=director`）＋mocked API 在 390/768/1440px、含資料/空狀態/loading 骨架屏/完整檢視四種情境下截圖驗證。
- 徽章數字曾一度改用 `AtBadge`（dot+label），但比對 `NotificationsCenter.vue` 既有用法後發現 `AtBadge` 全站慣例只用於文字類別標籤（如「請假申請」），從未用於純數字計數；改回沿用既有 `.wp__badge` 數字圓標樣式，避免創造新的不一致慣例。
- 課表回報（`sd-card`，整卡可點擊導頁）與補課案件（`exception-workflows-sec`，含多重 loading/error 狀態與候選時段巢狀 UI）因互動行為與其他卡片明顯不同，本波刻意不強制套殼，留待後續 wave 個別處理。
- 純前端結構調整，未改任何 API、繳費／審核／排課邏輯。`vite build` 全綠，`AtCard`/`AtEmpty`/`AtMetric` 既有單元測試全過。

## 2026-07-29 — fix(dashboard): 主任總覽頁面 Wave A —— 收斂重複資訊源 + 修正文字換行 bug

- 主任反映總覽頁面「很亂」。盤點後發現同一份「今天要做什麼」被拆成三套機制各自呈現：E-OPS-TRUST 決策中心、「今日優先處理」風險卡、以及最上方待辦小卡（action-lane）——待審核評量數甚至同時出現在三處。程式碼裡已有註解證實這是已知重疊（`// Trust decisions ... avoid duplicate prompts`），但「今日優先處理」與 action-lane 仍完整重複同一組訊號（待到班／催繳／補點名／補課／待審核／家長回饋）。
- 移除「今日優先處理」（`priority-risks`）整段：其資料完全是 action-lane 既有訊號的重新包裝，刪除後不影響任何業務邏輯（`directorPriorityRisks` computed、`handleDirectorPriorityRisk`、專用的 bypass 追蹤函式與 CSS 皆隨之移除；`lib/directorPriorityRisks.js` 與其獨立單元測試維持不動，未來若需要保留可再接回）。
- 修正真的 CSS bug：「查看範例格式」按鈕（`.ac__format-link`）缺少 `white-space: nowrap`（同層 `.ac__label` 有），可用寬度被壓縮時中文文字會逐字換行；已補上。
- 頁首英文 kicker `"Campus Operations Command"` 改為中文「今日營運總覽」，並將 letter-spacing 對齊站內既有中文 label（`.section-label`）慣例，不再套用為英文設計的寬字距。
- 純前端結構調整，未變更任何 API、繳費／審核／排課邏輯。已用真實 Vue 元件（非重繪版）搭配 mocked API，在 390px／768px／1440px 實際截圖驗證，`vite build` 全綠。後續 Wave（At* primitives 統一卡片殼、版面密度分組）另案處理，詳見改善計劃。

## 2026-07-29 — fix(learning): 學習評量表工具列大瘦身 + 批次核准改為「選取模式」+ 圖示字型自架

- 使用者實測回報：手機上批次核准的勾選框「很怪」、位置醜、還看得到英文字，整頁架構也很亂。實測後發現進入評量表要先滑過 6～7 排堆疊的控制列（分頁籤、篩選 chip、篩選條件卡、顯示模式切換）才看得到第一筆記錄；先前 #1510 加的勾選框又是每張卡片永遠顯示，就算只想單筆審核也擺脫不掉。
- 修法（參考 Gmail／Files app 的清單批次操作慣例、Carbon／Fluent 等資料密集後台的批次工具列模式）：
  1. 「篩選條件」進階篩選卡片改為預設收合，只有已有啟用篩選時才自動展開（原本永遠展開，佔用整排）。
  2. 批次核准改成「選取模式」：新增「批次操作」按鈕，未點擊前不顯示任何勾選框；點擊後才出現勾選框 + 全選本頁 + 批次核准／需修改／退回工具列，選取中的卡片/列會反白標示；完成批次操作或切換分頁會自動退出選取模式。
  3. 批次工具列改成「上：全選＋已選筆數／下：三顆等寬操作鈕」兩排固定版面，取代原本 flex-wrap 在窄螢幕擠成「3 顆＋孤伶伶 2 顆」的不對稱換行。
  4. 「還出現英文」的根因：全站圖示字型（Material Symbols）原本即時連 Google Fonts CDN，一旦字型連線失敗，圖示會退回顯示英文 ligature 名稱（如 `event`、`view_list`）。改為自架字型檔（`frontend/public/fonts/`），不再依賴外部 CDN 在渲染當下成功，比對照大公司做法（不依賴第三方 CDN 撐介面關鍵資源）。
- 純前端調整，未變更任何 API 或審核規則。已用真實 Vue 元件（非重繪版）搭配 mocked API、並刻意封鎖外部字型網域，在 390px／1440px 視窗實際截圖驗證圖示正確渲染、版面不再換行，全流程無 console 錯誤、`vite build` 全綠。

## 2026-07-29 — fix(course-packages): 總堂數修改後同步課程剩餘堂數（in-app #208）

- 主任把方案總堂數往下修改後，方案本身的剩餘堂數立即正確，但同方案內每堂課各自的剩餘堂數欄位不會跟著更新，主任優先風險清單因此顯示舊的（過高的）剩餘堂數。
- 修法：總堂數變動後自動呼叫既有的方案重新結算工具，讓每堂課的剩餘堂數與方案同步，不需要再手動觸發重新結算。純讀寫同步，無新增結算邏輯、無 migration。

## 2026-07-29 — feat(tuition): 順延重疊下一期警示（#1100, FD-3）

- `AlertController` 新增 read-only `newer_course_overlap` 欄位：當本期（A）`EndDate` 因請假順延而觸及或超過已預購下一期（B）`StartDate` 時，於主任繳費頁面標示重疊，不自動變更任何日期。
- 前端新增 `formatNewerCourseOverlapWarning()`（`studentClassDisplay.js`）與繳費頁不分繳費狀態顯示的「期間重疊」badge。
- 對齊 FD-3（順延語意維持 append-only，B 期起始日絕不被靜默位移；任何位移需明確、可稽核、對使用者可見）。純顯示層，不寫入任何 session／billing 資料。
- 回歸：`TuitionAlertsApiTest`（重疊 true/false 兩情境，並斷言 A/B 日期未被寫入變動）、`studentClassDisplay.test.js`。

## 2026-07-28 — fix(course-management): RenewMonthlyModal 防呆 current_end_date 無效日期

- Sentry PHP-LARAVEL-26（#1486）：`computedEndDate` 對 `props.form.current_end_date` 直接 `new Date(...)` 再呼叫 `toISOString()`，若該字串無法解析會產出 Invalid Date，`toISOString()` 對 Invalid Date 會丟 `RangeError: Invalid Date`。
- 修正為先檢查 `Number.isNaN(parsed.getTime())`，解析失敗時退回 `new Date()`（今天）當基準，不再讓整個月結續約 modal 崩潰。
- 純前端防呆，無 migration、無後端行為變更。

## 2026-07-29 — fix(learning): 評量批次核准在手機上找不到（card view 缺選取框）

- 學習評量表在寬度 < 760px 時預設切到卡片檢視（`viewMode='card'`），但批次核准／需修改／退回只做在桌機的表格檢視裡，卡片檢視完全沒有選取框，導致手機上永遠選不到任何一筆、批次列永遠不會出現——issue #1131 先前的程式碼稽核只看了桌機表格，沒發現這個落差。
- 修法：卡片檢視每張卡加上選取框，並加「全選待審／取消全選」按鈕，共用既有的批次核准邏輯，後端無需改動。
- 追蹤：#1131（重開）。

## 2026-07-29 — fix(security): confirmPayment() 補上分校/老師授權檢查 [P0 IDOR]

- `StudentClassController::confirmPayment()` 先前沒有任何授權檢查，任何已登入的主任或老師只要知道／猜到別分校的 `StudentClassID`，就能把該課程標記為已繳費——同 controller 其他寫入方法（`update`／`destroy`／`renewalPreview`）都有做的分校/老師歸屬檢查，唯獨這支漏掉。
- 修法：補上與其他方法一致的 `authorizeStudentClassAccess()` 檢查，並新增跨分校/跨老師 403 與同分校成功案例的回歸測試。
- 追蹤：#1504。

## 2026-07-29 — fix(course-management): 補課／補登過去時段前加確認，避免靜默自動核准評量

- 新店黃奕暟 7/28 誤加課事件根因：補課／補登（Quick Add Session）選到已過去的時段時，`auto_approve` 預設勾選會讓系統直接把該堂標記已上課＋自動核准評量，全程無任何確認，事後才由主任發現並手動取消。
- 修法：`checkAddSession` API 新增 `is_ended` 欄位；前端偵測到「已過去時段 + 自動核准」時顯示明確警告文案，送出前跳二次確認，取消即不送出。Checkbox 文案補上「評量同時自動核准」。
- 追蹤：#1507。

## 2026-07-29 — chore(ci): 前端補 ESLint `no-undef` 阻斷式檢查 + `ui-smoke.yml` 缺 secret 時可見警告

- 課程管理頁 P0 事故（見下方）的完整事後補強：前端過去完全沒有 TypeScript 或 ESLint，`vite build` 不會攔「引用未宣告變數」這類錯誤。新增 `frontend/eslint.config.js`，只開 `no-undef`（用今天的真實 bug 反向驗證過會攔住），接進 `npm run build` 第一步（CI「Vite build」步驟即會執行）。
- `.github/workflows/ui-smoke.yml` 新增「Warn if smoke secrets are missing」步驟：`SMOKE_DIRECTOR_USER`/`SMOKE_TEACHER_USER`/`SMOKE_BASE_URL` 任一缺少時印出 `::warning::`，讓「這條 E2E 防線目前被跳過」在每次 CI run 都可見，不用翻 log 才發現（TD-070）。
- 追蹤：TD-070（director smoke 帳密尚待補）、TD-071（`no-unused-vars`／完整 ESLint ruleset 尚待 baseline-gate 後開啟）。

## 2026-07-29 — fix(course-management): P0 課程管理頁整頁空白（ReferenceError）

- 課程管理頁自 07:39 部署（#1409）起，任何角色打開都整頁空白（外層 topbar／分校選單仍在，內容區完全沒渲染）。
- 根因：`useCourseSessionsDisplay.js` 的 `return` 物件引用了 `SESSION_NOT_OCCUPYING_QUOTA`，但該常數只存在於 `sessionOccurrenceFilter.js`（未 export、也未被 import），元件每次 `setup()` 執行到 return 就丟出 `ReferenceError`，中斷整個 Vue 元件掛載。
- 修法：移除該筆未使用、未宣告的殘留引用（`CourseManagement.vue` 本來就沒有消費這個值）。

開發備註：新增 regression test `useCourseSessionsDisplay.test.js`（真的呼叫 composable 本體，斷言不拋錯）——原本唯一的 `useCourseSessionsDisplay.occurrence.test.js` 是鏡像邏輯測試，從未 import 真正的模組，CI／`vite build` 都沒有實際執行過這個 return 陳述式，故未攔住。已納入 `vitest run`（CI `test:unit:cov` 既有 glob 涵蓋，無需另外接線）。`npm run test:calendar` 全綠、`vite build` 全綠。

## 2026-07-29 — chore(ci): `scripts/ci/branch-policy.mjs` 白名單補 `claude/` 前綴

- Claude Code on the web / CCR session 在此 repo 自動建立的分支固定是 `claude/<slug>` 命名，但白名單只列了 `cursor/`（Cursor agent），導致本次 P0 修復的 PR 被 Presubmit CHECK 1 擋下。
- 補上 `claude: { status: 'accepted', riskHint: 'R0+' }`（比照 `cursor` 項），並在 `scripts/ci/gov.test.mjs` 加對應斷言。

## 2026-07-29 — feat(release-notes): 教職員版本更新改為顯式 STAFF_UPDATES（與 CHANGELOG 拆分）

- 新增 `docs/STAFF_UPDATES.yml` 為教職員「版本更新」唯一權威；`notesForRole` 不再自動發布 CHANGELOG 投影。
- CHANGELOG 僅產生 `changelogDraft.generated.js`（AI 起草用），並強制依日期降冪排序。
- 新增使用者文案閘門 `userFacingCopyGate`（擋內部 ID／class／Phase 等；失敗即停，不刮字改寫）。
- 家長仍只讀 `PARENT_UPDATES.yml`（R45）；STAFF 檔禁止 `parent` audience（R85）。
- 操作指南：`docs/GUIDE_STAFF_UPDATES.md`。

開發備註：UI 標示改「最新更新」；分類改「你現在可以／我們修好了／操作更順手／需要你注意」。回歸 `npm run test:release-notes`。

## 2026-07-29 — fix(billing): 繳費提醒與課程列表付款真相對齊（#959，G-009）
<!-- release-notes: staff_update=staff-2026-07-29-tuition-alert-payment-truth-959 -->

- `AlertController::computePaymentStatus()` 新增 `hasInvoicePayment` 參數：`已繳費 = Paid=1 OR 有未作廢 Invoice 已結清付款`，與 `StudentClassController` 課程列表（`lastPaidAtByStudentClassIds`）的權威判斷對齊。
- 同步修正 `outstanding` 計算：發票已結清但 `Paid` 未同步更新的課程，不再顯示欠款。
- 呼叫端已於同一輪迴圈算出 `$invoicePaidAt`，本次僅重用既有資料、不新增查詢。

開發備註：回歸 `TuitionAlertsApiTest::test_payment_status_paid_when_settled_via_invoice_despite_paid_flag_zero`。CoursePackage 側（`computePackageCountPaymentStatus`）已有等效 OR 邏輯，本次不動。

## 2026-07-24 — feat: Course Continuity 群組 API MVP（#1382）

- 新增 `course_contract_groups`／`course_contract_group_members`（空表；不物理 merge 合約）。
- 主任 API：列表／建立群組／加入成員／解除關聯；拒絕跨學生／跨校／package。
- 解除關聯不刪 `StudentClass`；財務／堂次／評量維持原合約。

開發備註：RFC 方案 A。不含自動 backfill、#1130 repair、群組 UI。回歸 `CourseContinuityGroupApiTest`。

## 2026-07-24 — fix: Epic A/D Phase 1 — 有效堂次共用過濾 + 調課 dialog 內錯誤

- 課程管理與行事曆共用 `sessionOccurrenceFilter`（有效堂次／幽靈取消／額度例外）。
- 調課失敗（含衝堂名單）改顯示在 dialog 內；提交中 disable，拿掉多餘 `confirm()`。
- 課程管理篩選列與表格改 denser（Epic D 逐步掃讀密度）。

開發備註：承接 #1402；對齊 RFC Platform Opt Phase 1（Epic A 收尾 + Epic D 噪音／確認 UX）。

## 2026-07-24 — fix: 調課後課表穩定（系列契約 vs 單堂例外）

- 課程管理預設只顯示有效堂次；已取消／內部調課 bookkeeping 改為可展開摘要，不再幽靈搶版面。
- 單堂調課會標記契約例外，且不再回寫固定 `week/time`；月結續約維持契約時段並在預覽警告未對齊的例外堂。
- 暫停課程可勾選是否取消剩餘排課（預設勾選）。

開發備註：對齊 Google Calendar／Tutorbase「this occurrence only」。`ContractScheduleMatcher`、`reconcile` 排除 `IsContractException`、`cancel_remaining`、renewal preview `open_contract_exceptions`。回歸 `ScheduleOccurrenceStabilityTest`。

## 2026-07-28 — fix(learning-records): R55 復活判斷收斂為單一共用政策

- 新增 `LearningRecordResurrectionPolicy`：`SYSTEM_RESURRECTABLE_VOID_REASONS` 白名單與「是否可自動復活」判斷收斂到單一位置。
- 修正 `ClassSessionController::restoreVoidedLearningRecord()`（leave→attended 自動復活路徑）從未檢查 `VoidReason` 的缺口——人工作廢的評量若剛好掛在曾經 `leave` 的堂次上，先前會被無條件復活；現在與 `LearningRecordController::store()` 共用同一份白名單判斷。
- `CourseLeaveCascadeService` 的請假撤銷復原刻意不動（只認 `VoidReason='一般請假'`，範圍本來就該窄）。
- 回歸：新增 `ClassSessionRestoreVoidedLearningRecordTest`（系統 cascade 原因仍自動復活；人工作廢原因不再被復活）；既有 `LearningRecordVoidedResurrectTest` 全數維持通過。
- 無 migration、無行為變更（reactive 路徑邏輯不變，只是搬了位置；proactive 路徑修正的是先前未覆蓋的邊界情況）。

## 2026-07-28 — chore(billing): 清償 TD-060 — 刪除 RemainingSessions 死碼重算路徑

- 刪除 `ClassSessionController::recalculateSessionCounters`（無 caller 死碼，count-based，與權威引擎 `SessionDeductionService::recomputeCounters` 並存、非分鐘感知）。
- 確認權威引擎已涵蓋 legacy `attended` 狀態相容性且更完整（含 `StudentSignIn`/ledger/orphan LearningRecord、分鐘制衍生）。
- 回歸測試改為直接驗證 `SessionDeductionService::recomputeCounters()`，斷言不變；同步清掉 `phpstan-baseline.neon` 對應豁免項。
- 架構稽核備忘 Pattern A 的第一項行動：衍生欄位（`RemainingSessions`）在復發前先排除掉一份未接線的重複實作。無 migration、無行為變更（死碼本來就無 caller）。

## 2026-07-28 — docs(architecture): 新增架構性不變式登記本（Pattern A-E）

- 新增 `docs/RULE_ARCHITECTURAL_INVARIANTS.md`：追蹤「同一種形狀會反覆出現」的架構級根因（區別於 `TECH_DEBT.md` 的單點技術債），收錄本次架構稽核備忘的五種模式（衍生欄位單一寫入、主檔狀態轉換 cascade、多畫面單一投影、前後端契約、授權集中化）與目前已知實例。
- 收錄本次 session 的具體案例作為登記起點：`IsContractException`（R83/R84）、`RemainingSessions`（TD-060）、`LearningRecord` 復活政策（R55）、`ScheduleController` 補請假重複（TD-069）、前後端路由契約檢查。
- 無 migration、無程式碼變更。

## 2026-07-28 — fix(learning-records): 家長留言預覽增加「回覆家長」入口（in-app #210）

- 評量列表點擊「家長留言」chip 開啟的預覽原本只有內容/時間，找不到回覆處；新增 `FeedbackInlinePreview` 元件內建回覆按鈕，直接開啟評量詳情完成回覆。
- 純前端變更，沿用既有 `LearningRecordFeedbackController::staffReply()` 權限與資料，無 migration、無後端改動。

## 2026-07-28 — feat(learning): 家長留言 awaiting_staff_reply inbox（P0）

- 新增 authoritative `awaiting_staff_reply`（與 unread 分離；**不**沿用 `analytics.unreplied_records`）。
- Parent upsert：相同內容 idempotent no-op；實際修改內容會 append parent reply 以同表 `(created_at, id)` 穩定排序。
- API：`GET me/awaiting-reply-count`、`learning-records?feedback=awaiting_reply`（teacher／director；不擴 super_admin）。
- 前端：TeacherHome 固定「家長留言」卡、評量頁一級「家長留言」Tab、modal 回覆模式。
- 無 migration／backfill。Implementation PR 不自動 merge／deploy。

## 2026-07-28 — refactor(scheduling): IsContractException 搬進 ClassSessionObserver（R83 結構性根治）

- `ClassSessionObserver::saving()` 在任何 `ClassSession->save()` 時，只要時間欄位有變動且該次寫入未明確指定 flag，自動用 `ContractScheduleMatcher::applyExceptionFlag()` 重算 `IsContractException`；明確指定時尊重呼叫者意圖不覆蓋。
- 刪除 3 處重複實作（`ClassSessionController`、`StudentClassController` 加課、`RescheduleSessionService`）；新寫入路徑（如 `SubstituteController` 代課復原、`ClassSessionContractReflowService`）現在自動獲得正確行為，不需個別接線。
- 回歸：新增 `ClassSessionObserverContractExceptionTest`；既有 `StudentClassScheduleDriftExceptionTest`／`RescheduleMarksContractExceptionTest` 全數維持通過。
- 無 migration／無行為變更，純內部結構重構。

## 2026-07-28 — fix(scheduling): atomic 調課標記 IsContractException（防 realign 還原）

- `RescheduleSessionService` 調課後同步 `IsContractException`（與 PATCH class-sessions / #556 對齊）。
- 避免單堂調到非契約時段後，被 `force_partial_rebuild`／堂次偏移同步拉回固定排課時間（症狀：重整／儲存後課表回原時段）。
- 回歸：`RescheduleMarksContractExceptionTest`。

## 2026-07-28 — fix(scheduling): ADR-006 acceptance amendments（dormant／Ensure gates／ADR status）

- explicit + dormant → `auto_ensure_eligible=false`（`SKIP_DORMANT`）；禁止自動 Ensure。
- Ensure `--execute`：production reason 優先於 flag；blocked execute → non-zero exit。
- ADR-006／INDEX 狀態改為「工具已 merge；production 未啟用」。

## 2026-07-28 — docs(adr): ADR-006 Phase 3B session_coverages migration proposal（awaiting GO）

- 新增空表 migration 提案 `session_coverages` + `docs/proposals/ADR006_PHASE3B_SESSION_COVERAGES_MIGRATION.md`。
- **未 merge／未 migrate／未啟用 coverage 寫入** — 需 Founder GO。

## 2026-07-28 — feat(scheduling): ADR-006 Phase 3A pool coverage planner（read-only）

- 新增 coverage state machine（`none/held/consumed/released`）與 `AllocateSessionCoverage`／`ReleaseSessionCoverage` dry-run planner；`sessions:plan-coverage`。
- **不**寫 coverage 表、不扣堂、不 merge migration（持久化另 PR + Founder GO）。

## 2026-07-28 — feat(scheduling): ADR-006 Phase 2 shadow horizon（read-only）

- 新增 `sessions:shadow-horizon` + `ShadowSessionHorizonService`：Preview vs Ensure dry-run 對照、drift／shortage 指標；**永遠唯讀**。

## 2026-07-28 — feat(scheduling): ADR-006 Phase 1B EnsureSessionHorizon（default-off）

- 新增 `sessions:ensure-horizon` + `EnsureSessionHorizonService`：dry-run 預設；`FEATURE_ENSURE_SESSION_HORIZON` 關閉；production `--execute` 硬擋；ES → `BLOCK_POOL_SHORTAGE` 整批 no-write；物化僅走 `upsertSlot`。
- **未**啟用 Kernel／production activation／真實 backfill。

## 2026-07-28 — feat(scheduling): ADR-006 Phase 1A PreviewSessionHorizon（read-only）

- 新增 `sessions:preview-horizon` + `PreviewSessionHorizonService`：Commitment 分類、28 天 occurrence covered／uncovered、pool_projection（不含成員 pool 剩餘）、分校 fail-closed。
- **不**建立 ClassSession、不扣堂、不啟用 Ensure。

## 2026-07-28 — feat(scheduling): ADR-006 Phase 0 唯讀 prepaid horizon 報告（slice 2/2）

- 新增 `sessions:report-prepaid-horizon-phase0`（**read-only**）：explicit MF 7／28d、Q2 reason 拆分、pool shortage、FSG 對照、人工補排近似、StudentClass adapter 評估。
- `PrepaidHorizonPhase0Reporter` + Feature 回歸；synthetic sample `docs/artifacts/adr006-phase0-sample-report.json`。**不**寫 ClassSession、不 activate generator。

## 2026-07-28 — feat(scheduling): ADR-006 Commitment classifier helpers (Phase 0 slice 1/2)

## 2026-07-28 — docs(adr): ADR-006 預付堂次 horizon × Schedule Commitment 決策包

- 新增並修訂 `docs/ADR_006_prepaid_session_horizon_and_commitment.md`：**Accepted — Phase 0 evidence collection authorized**（仍 not implemented／not production-ready）。
- Founder ACCEPT WITH AMENDMENTS：Commitment 三類（explicit／legacy_inferred／conflict）；28 天 v1 server default；Preview 可顯示 uncovered、Ensure 遇 ES → `BLOCK_POOL_SHORTAGE` 整批 no-write；`StudentClass` 條件式 v1 adapter + fingerprint（非永久 SSOT）。
- Reason codes 拆分 `INFO_FLEXIBLE_NO_COMMITMENT`／`BLOCK_COMMITMENT_*`／`LEGACY_INFERRED_CANDIDATE`；廢止含糊的 `SKIP_NO_COMMITMENT`／`SKIP_POOL_SHORTAGE`。
- 對齊 #1062 Track A、ADR-005、G-010、F4／#1465。本 PR docs-only；下一獲准範圍僅 Phase 0 唯讀報告。

## 2026-07-28 — fix(ops): post-merge smoke 重試 director schedules 403

- `#1465` merge 後 health／version 已過，但 `director GET /schedules` 偶發 403 觸發 rollback。
- `post-merge-smoke.sh`：優先取有 Approved 分校的 director token；`403`／`500` 重試並附 body 片段，避免誤 rollback 前端-only 部署。

## 2026-07-27 — fix(ux): 共用方案堂次區狀態語意與預排 chip 分流

- 共用方案「排程列數與購買堂數不一致」改為中性「目前只排定部分堂次」；請假待補／真超排仍分級警告。
- 共用方案成員課程不顯示方案池剩餘堂數；在 package-level scheduled allocation aggregate 建立前，不推導成員課程尚可排或未排 N 堂。
- 堂數制預排 chip 不再呼叫 `ensure-projected`（避免 422）；改開可行動 dialog → 補排預填。物化 capability 嚴格限 `ScheduleMode=date`。
- 堂次 cache miss 改 actionable dialog（再試一次），不再只靠原生 alert。不動扣堂／方案池 SSOT。

## 2026-07-27 — docs(adr): ADR-005 排課多入口 × 具名 command 邊界

Accepted direction（文件）：保留 StudentsList／SmartCalendar／CourseManagement 三 task surface；每個 mutation 對應具名 command；command 只收完成意圖必要的 target values，不接受前端回傳可推導的 current／derived domain truth。首實作 slice（另 PR）：`RestoreContractTeacher`。見 `docs/ADR_005_scheduling_named_command_boundaries.md`。

## 2026-07-26 — design(ui): UI Foundation + 主任收件匣 pilot

- 新增 ops UI Foundation tokens 與 inbox 實際使用的 `At*` primitives；文件見 `docs/design/ALLTRUE_UI_FOUNDATION.md`。
- Pilot：主任收件匣（結構／狀態／密度；業務邏輯與 API 不變）。學生列表見 stacked follow-up PR。
- Visual fixtures 僅在 `frontend/e2e/fixtures/`；production `public/` / `dist_build` 不含 mount harness。
- Merge evidence：真實 Vue inbox Playwright + mocked API（390／768／1440）。

## 2026-07-26 — design(ui): 學生列表 UI Foundation pilot (PR B)

- Stacked on inbox foundation PR：`StudentsList` 接入 `At*`（含 `AtIconButton`）、unit/a11y、真實 Vue Playwright、durable evidence。
- 補齊 UI audit + migration sequencing；CI 上傳 `ui-foundation-page-evidence` artifact。
- 業務邏輯／API／DB／權限不變；supersedes monolithic #1449 students half。**No size-gate exception**（不沿用 #1450）。

## 2026-07-26 — ci(governance): failure taxonomy + fast preflight（G1）

- 開發備註：新增 `npm run ci:preflight` / `sync:generated`、failure taxonomy、branch policy（含 `sec/`）；見 `docs/governance/CI_GOVERNANCE.md`。不改 production 業務邏輯。

## 2026-07-26 — chore(repo): PR／Issue／branch／docs hygiene

- 同步 Parent Binding 文件狀態：PB-00 = **IMPLEMENTED / DEPLOYED — PRODUCTION ACTIVATION PENDING**（#1446 merged；#1436 closed by merge；Pi ops activation／`effective=true`／7-day baseline 未完成；PB-01～09 未開始）。
- 修非 archive 壞連結；branch hygiene：刪支必記 tip SHA；`archive/<branch>` tag **非預設**（僅 unique unmerged keep-value）。
- 無 production code、無 deploy、未合併產品 PR。

## 2026-07-26 — feat(parent): PB-00 家長綁定 PII-safe observability（#1436）

- Stable internal `reason_code` + append-only `parent_binding_attempts`（fail-open）；flag `PARENT_BINDING_OBSERVABILITY` **default-off**；dedicated `PARENT_BINDING_PHONE_HMAC_KEY`（no APP_KEY）；ops `parent-binding:report --format=json`。不改外部文案／成功路徑；PB-01～09 未開始。

## 2026-07-26 — docs: 家長綁定 ADR Accepted（Hybrid；PR #1434）

- Founder Accepted：max_uses=1；TTL 7d（24h/72h/7d）；cap 4；read_only 365d→suspended；revoke→session；BindingRequest 自助；sunset ≥80%/30d/support&lt;10%（無硬日期）；OTP∉P0–2。Docs-only at merge；PB-00 後續由 #1446 實作 observability。

## 2026-07-26 — fix(parent): 家長更新卡改為顯式 PARENT_UPDATES 投影（B+）

- 家長入口「與您有關的更新」不再從教職員 CHANGELOG 以關鍵字自動標 `audience:parent`。
- 新增 `docs/PARENT_UPDATES.yml` 為家長公告唯一來源；title／summary／details 獨立，禁止 fallback 到 staff summary。
- 無家長更新或已過期時首頁隱藏該區塊；普通更新預設 30 天效期、最多兩則。
- 同步腳本一併產生 `parentUpdates.generated.js`；CI 檢查 generated 檔不得漂移。

開發備註：Founder Decision 2026-07-26 B+／§R45。行動型通知（重新綁定等）不在本卡範圍。首則家長文案：請假後未來日期不移動、尾端補課。

## 2026-07-26 — fix: 堂數制請假改為保留未來日期、只補尾堂

- 一般請假不再把後續堂次整排往後推（不再出現 silent vacated week）。
- 請假堂不佔堂號；下一個既有上課日承接下一堂；尾端最多補一堂。
- 整體順延改為明確 pause 能力，不作為一般請假預設。
- 請假預覽與課程管理／出缺勤文案同步；新增 vacated-week 掃描修復指令。

開發備註：Founder Decision 2026-07-26 / §R82。權威路徑 `CourseLeaveCascadeService`；repair：`repair:leave-vacated-weeks`。

## 2026-07-24 — docs: 品牌表面品味閘門（防再犯 #1386）

- 新增 `.cursor/rules/frontend-brand-taste.mdc`：Brand/Auth vs Ops 分流；Founder star 品味基準。
- `RULE_DESIGN_SYSTEM.md` §1.1 / §7：Login 可保留 glass/mesh；禁為 hex KPI 拆氣氛。
- `module-frontend.mdc` 指向該閘門。

開發備註：#1386 Agent 拆光 Login → #1412 還原；下次 UI 對齊 taste-skill / impeccable / awesome-design-md 等 star。

## 2026-07-24 — revert: 登入頁恢復 #1386 前視覺

- `Login.vue` 退回 DS polish pilot 之前的介面（玻璃／品牌 mesh 等）。
- 移除僅服務該 pilot 的 `login-polish` e2e；更新 design-hex baseline。
- 全局 `--ds-cta`／`AtButton` AA tokens 保留（不影響本次登入外觀還原）。

開發備註：Founder 要求退回 #1386 Login 視覺；行為（帳密／忘記密碼）不變。

## 2026-07-24 — docs: RFC 依 starred repos 做平台大改版規劃（無業務碼）

- 新增 `docs/architecture/RFC_PLATFORM_OPTIMIZATION_FROM_STARS_2026.md`：每項優化標明參考 repo、要學／不要學、落地位置。
- INDEX 登記為規劃件；不改應用程式行為。

開發備註：對齊 Founder star 清單（排程／帳務／UX／LINE／通知）；Companion #1382 與既有 maturity／AI-native roadmap。

## 2026-07-24 — security: 家長入口跨學生資料隔離（P0）

- LINE 綁定現在必須同時驗證學生姓名／學號與家長聯絡手機；不再接受只憑姓名或學號的綁定。
- LINE 自動登入改由後端向 LINE Profile API 驗證 access token，不再信任瀏覽器提供的 user ID。
- 既有無驗證證據的 LINE binding 暫停授權與推播，既有家長 session 到期；家長需透過安全流程重新綁定。
- Dashboard、切換學生、通知偏好與家長推播只採用已驗證 binding。

開發備註：P0 privacy containment。新增 `verified_at`／`verification_method`，回歸涵蓋偽造登入、舊 binding、無手機綁定與跨學生切換。

## 2026-07-24 — polish: Login 頁改吃 DS tokens（Epic #687 pilot）

- `Login.vue` 移除 glassmorphism／動態 gradient mesh／裝飾 emoji；表單與狀態改用 `--ds-*`。
- 新增 AA CTA tokens（`--ds-cta`／`--ds-on-cta`）；角色改 native radio card；raw hex 35→0。
- 長期截圖：`docs/reviews/login-polish-1386/`。

## 2026-07-22 — fix: 課程備註可正確儲存 emoji 與完整中文

## 2026-07-22 — fix: 建課衝突改為明確決策（試聽／加購／續報／獨立）

- 遇到同科進行中課程時，主任可選「建立試聽」「加購」「下一期續報」或「建立獨立課程」，不再只有含糊的強制建立。
- 建立獨立課程須填寫原因，系統會留下操作者與既有合約紀錄。

開發備註：#1379 follow-up。`EnrollmentConflictDecisionModal` + `force_reason` 審計（`create_trial`／`renewal_next_term`／`independent_parallel`）。尚非 Course Continuity 最終設計。

## 2026-07-22 — fix: 試聽建課不再被「同科同師日期重疊」擋死；行事曆快速排課補上強制建立

- 新建「試聽」課程時，不再套用續報用的 `overlapping_active_course` 攔截（試聽本意是旁聽正式課堂）；同科重複試聽仍會提示。
- 智慧行事曆「快速排課」遇到重複／重疊課程時，改跳出「仍要新增課程」視窗，不再靜默失敗。

開發備註：`EnrollmentService` 對 `class_type=trial` 跳過 #805 重疊守衛；`SmartCalendar` 補 `@duplicate-course` + force modal（對齊課程管理／學生管理）。回歸 `OverlappingCourseGuardTest::test_trial_course_is_not_blocked_by_overlapping_active_course`。

## 2026-07-24 — chore: 安裝 taste-skill（設計品味 Agent 技能）

- 建課備註可含 emoji、中文、換行與標點，不再因資料庫字元集錯誤整筆失敗。
- 若字元集尚未升級，系統回傳明確錯誤且不會留下半成品課程／堂次（不會默默刪除 emoji）。

開發備註：Refs #1378／F6。migration `2026_07_22_130000_convert_student_class_free_text_to_utf8mb4`；過渡 422 `memo_charset_incompatible`。Production migrate 需 Founder GO：`docs/runbooks/1378-memo-utf8mb4-execution-package.md`。回歸 `StudentClassMemoUtf8mb4Test`。

## 2026-07-22 — ops: in-app bug closure queue exhausted (active engineering)

- Active queue cleared: in-app #207 Phase C resolved after production deploy `7acb5803`.
- Final report: `docs/incidents/bug-closure-queue-final-report-2026-07-22.md`. Founder-parked items unchanged (#173/#1062/#1342/#189–191).

## 2026-07-22 — fix: 課程改老師不再改寫已上堂次的授課老師（in-app #207）

Fixed：在課程管理編輯「授課老師」時，已上過／已點名的過去堂次會保留原來的老師；未來堂次才跟新的合約老師。不必再逐堂手動設代課來「救」歷史紀錄。

開發備註：`StudentClassController` 在 TeacherID 變更時對過去堂次寫入 substitute-style `schedules` pin（原老師）；未來 `scheduled` 列仍走既有 `syncFutureScheduleTeachersAfterContractTeacherChange`。回歸 `ContractTeacherChangePreservesHistoryTest`（in-app #207）。

## 2026-07-22 — ops: in-app bug queue dump + Phase-C allowlist（#205/#198）

- Cloud agent 無法 `workflow_dispatch` 時，改以 request file push 觸發唯讀 bug queue dump，並對已上線修復的 in-app #205／#198 做冪等 Phase C（公開回覆＋resolved）。
- Founder 決策包：`docs/incidents/bug-closure-founder-decisions-2026-07-22.md`（#173／#1062／#1342／歷史帳務）。

# AllTrue Changelog

## 2026-07-22 — ops: #1342 人工交付授權 + outbound readiness + #1062/#1130 probes

- #1342：Engineering PASS／Operational Delivery BLOCKED；platform-ops 以既有主任 LINE 私訊／群組從 run `29686172773` 人工交付；tracker v2（checksum／delivered_at／acknowledged_at／deadline_at_risk）。
- 永久治理：`docs/governance/OUTBOUND_READINESS_GATE.md` + `scripts/outbound-readiness-gate.py`（artifact ≠ 交付）。
- #1062/#1130：`scripts/ops/stranded-classify-probe.php` — 24h/72h producer proxy、exposure total/active-21d/dormant、group/pair/student/course/future-active。

## 2026-07-22 — feat: Action Inbox P0（fail-closed／分頁／DTO）

- 唯讀 `action-inbox`+`count`+`cases/{id}`；fail-closed 校區；`cases_unresolved`/`cases_candidate_ready`/`badge_total`/`urgent_total`；DTO+`no-store`；不雙寫 leave Notification（§R81）。deprecated：`cases_open`/`needs_attention`→2026-09-01。

## 2026-07-22 — fix: 排課摘要「補登已上」改以堂數顯示（同日多時段）

- 新建課程日曆摘要「補登已上／預排未上」改為「X 堂（Y 天）」；同日兩個固定時段不再把日期數誤當堂數。
- 摘要計數與送出 `session_plan` 共用 `schedulerSessionExpand` 展開來源；未改補登語意或後端扣堂。

## 2026-07-21 — chore: 收據 hotfix closeout（刪 skip、fail-fast、電子收據命名）

- 刪除 #1197 錯誤假設留下的 skipped `/receipts` 測試（active suite skip=0）。
- `reportId` 必須為正整數，否則不發 request（避免 `/payment-reports/NaN/receipt`）。
- Modal 標題改回「電子收據」，避免在 T3 Receipt Domain 完成前暗示完整法定文件能力。
- 補切換 reportId 不殘留上一筆資料的回歸測試。

## 2026-07-21 — fix: 帳務中心收據改回既有 payment-report API（#1197 回歸）

Fixed：主任在帳務中心點「收據」不再出現「請求失敗（404）」。收據改回使用既有核帳收據接口顯示學生、分校、金額與收據編號；未新增新的收據資料表或作廢／PDF 功能。

開發備註：根因是 PR #1197 前端改打尚未存在的 `/api/v1/receipts*`（§R79）。Hotfix：`ReceiptModal` + `paymentReportReceipt` adapter → `GET /api/v1/payment-reports/{id}/receipt`。測試：`ReceiptModal.test.js`、`TuitionCollectionReceiptEntry.test.js`。

## 2026-07-20 — fix: nightly 評量回補會還原「已上但作廢」的評量（#1078）

- 老師在已上課堂次找不到可填評量時，系統夜間回補會把先前因請假流程作廢的評量恢復成待填，不再卡住。請假堂次的作廢評量維持不變。

## 2026-07-19 — ops: #1342 四校審核追蹤 + repair bundle gate + TD-059 活監測

- 四校主任審核任務（owner／SLA／計數）寫入 tracker；PII／artifact 14d；repair bundle + `ops-leave-cascade-repair.yml`；TD-059 `ops-td059-monitor.yml`（異常才升 P1）。#1342 待主任；下一工程=#1062 唯讀分類。

## 2026-07-19 — ops: 主任 leave-HC 審核包 + #1262 關閉 + TD-059 monitor

- 19 筆 high-confidence 改分校主任 CSV（核准／保留／查證；不用 Founder 讀 session ID）。#1262 overnight 證據達標關閉。TD-059 決策 B（monitored risk，不 schema）。

## 2026-07-19 — ops: TD-059 audit NO-GO schema + leave HC×19 pack

- 共用包 46 組有使用，但部分分鐘扣堂命中=0、無 drift → TD-059 維持 defer。主任 leave HC 19 筆紅acted CSV 已落地（#1342，不 execute）。

## 2026-07-19 — ops: follow-up Issues #1342/#1343 + TD-059/leave 唯讀稽核 workflow

- Closeout／TECH_DEBT 回填 [#1342](https://github.com/jerry200176-png/AllTrue_System/issues/1342)、[#1343](https://github.com/jerry200176-png/AllTrue_System/issues/1343)；`ops-portfolio-td059-leave-audit.yml` 唯讀盤點 open Issues、TD-059 影響、高信心 leave CSV（不 execute）。主任審核 SOP：`docs/sop/LEAVE_CASCADE_DIRECTOR_CSV_REVIEW.md`。

## 2026-07-19 — ops: leave/makeup closeout（Founder 不批准批次 repair）

- Production evidence 通過；歷史 96 候選改主任審核 CSV／`--session-ids` 執行（禁止 re-scan 整批寫入）。詳見 `docs/incidents/leave-cascade-slot-times-closeout-2026-07-19.md`。

## 2026-07-19 — ops: leave/makeup evidence closeout + 扣堂 net idempotency
- `evidence:leave-makeup-closeout` + ledger 淨額 idempotency；歷史 `--execute` 仍需 Founder 批准。

## 2026-07-19 — fix: 補課加長按實際分鐘扣堂

Fixed：補課若排得比契約一堂更長（例如契約 2 小時、補課 3 小時），點名後會依實際上課分鐘扣 entitlement，不再固定只扣一整堂。預付包堂扣的是餘額分鐘，不自動加收現金。

開發備註：`SessionDeductionService::resolvePartialMakeupMinutes` 允許 makeup minutes > perSession（§R59）。測試：`PartialMakeupDeductionTest`（180 分）。共用課程包分鐘鏡像仍見 TD-059。

## 2026-07-19 — fix: 請假順延不再錯置其他星期時段

Fixed：多星期固定課（例如週三 17:00–19:00、週六 10:00–12:00）請假順延後，其他星期不會再被改成請假那天的鐘點。

開發備註：`CourseLeaveCascadeService` 移動／append／undo 對齊目標日契約時段（§R77）；`IsContractException` 不重寫。歷史漂移 dry-run：`php artisan repair:leave-cascade-slot-times`。測試：`LeaveCascadeMultiWeekdaySlotTimesTest`、`RepairLeaveCascadeSlotTimesTest`。

## 2026-07-19 — fix: 單堂改時段費用與畫面說明一致

Fixed：課程管理「備註／調整時段」的費用說明與後端寫入一致——按堂計費時段調整不改本堂與課程總費用；按時計費儲存後會依實際時長更新此堂費用並同步課程總費用。避免畫面寫「不影響」但帳卻被改（或相反）造成誤判。

開發備註：`ClassSessionController::syncSessionChargeForTimeChange` 恢復 session／hour 分支（F7／§單堂費用固定）；`SessionEditModal` 文案對齊；`ClassSessionChargeTest` 守護固定費與按時 delta。

## 2026-07-19 — fix: 主任堂數異常只呈現可核對的帳務候選

Fixed：主任營運決策中心不再把已停用的歷史課程或「購買堂數從未初始化」的舊資料混入一般堂數差異；只有仍啟用且有正數合約基準的課程會進主任核對名單，其餘仍保留為獨立工程監測訊號。

開發備註：`BusinessDigestService` 保留既有差異總數相容欄位，新增 reviewable／active legacy／inactive history 拆分；全程唯讀，不修改任何課程、堂數或付款資料。

## 2026-07-18 — fix: 代課挑選排除同一學生續約佔用（in-app #203）

Fixed：為學生指派代課老師時，不再把「同一學生」既有的續約／雙軌 scheduled 佔用顯示成衝堂；同分校與跨分校檢查同樣排除該學生。其他學生的真實佔用仍會正確阻擋。

開發備註：`exclude_student_id` 加入 availability API、`SubstituteService` busy 收集與 `ScheduleGuardService` 代課路徑（R74）。前端代課挑選器帶入 `context.student_id`。

## 2026-07-18 — fix: 跨老師拖曳會完整轉移代課與時段 (#1282)


Fixed：主任把單堂課拖到另一位老師欄位時，即使同時更改日期或時間，系統也會用同一個確認流程一次完成代課與改時段；不再只移動畫面上的課、卻把點名與評量留給原老師。歷史堂次可在原日期內更正老師與時間，登入失效或操作失敗時不會假裝成功。

開發備註：跨老師手勢統一走 atomic substitute endpoint；修正 Supabase compatibility mutation-return contract、reschedule anchor idempotency/重掛、legacy 兩階段 422 補償與 cross-date ClassSession 延後物化。未自動修改任何 production 歷史資料。

## 2026-07-18 — fix: 已取消堂次不再佔用代課老師時段

Fixed：代課挑選與容量檢查不再把「ClassSession 已全部取消／請假，但 schedules 仍標 scheduled」的殘留例外當成真實衝堂或已滿；主任可正常指定代課。沒有對應堂次紀錄的補課排程仍會正確佔用時段。

開發備註：`StaleScheduleExceptionFilter` 套用於 `SubstituteService` 與 `ScheduleGuardService`（#1296／in-app #203／R72／F1）。既有生產殘留 row 部署後立即無害，無需資料修復。

## 2026-07-18 — fix: 出缺勤「同一堂變兩堂」跨約／停用殘留防護

Fixed：老師出缺勤「今日待點名」若因舊約停用殘留或跨課程同日同時段雙列，畫面只會保留一堂；已停用課程的待上課次不再出現在預設列表。夜間向前產生堂次若偵測到同一學生同時段已有其他合約，會略過並留下稽核紀錄。主任日報會標示跨約待點名重疊與停用殘留筆數。

開發備註：Attendance `student_id|date|start` 去重（優先 Stop=0、SessionCount>0）；`ClassSessionController::index` 預設隱藏 Stop=1 scheduled（`include_stopped_scheduled=1`）；`ForwardSessionGenerator` cross-SC skip + `cross_sc_slot_conflict` log；`repair:duplicate-sessions --case=scheduled-cross-sc`；digest `scheduled_cross_sc` / `orphan_stop_scheduled`。Incident：`docs/incidents/2026-07-18-xindian-duplicate-attendance-slots.md`。

## 2026-07-18 — fix: 調課改為單一交易，並顯示點名建立來源

Fixed：課程管理、堂次編輯與行事曆的調課現在只有在原堂、目標堂、實際課堂與評量全部同步成功後才會顯示完成；任何衝堂、找不到原堂或網路錯誤都不會留下半套資料，也不會再假成功。出缺勤歷史新增「建立來源」，可直接看是誰人工登記，或由系統／刷卡建立。

開發備註：新增 `RescheduleSessionService` 原子交易、精準 occurrence 定位、分校授權與冪等重試；三個前端入口共用 `commitReschedule()`。架構決策見 `ADR_004_atomic_reschedule_boundary.md`。

## 2026-07-18 — fix: leave attendance is closed when it is created (#1262)

Fixed：主任從行事曆、課程請假或出缺勤編輯建立「請假」時，系統會直接保存該堂完整的起訖時間，不再把請假誤記成仍在補習班內、等到隔夜才修正的未簽退紀錄。

開發備註：集中所有請假出缺勤寫入、加入 model fail-closed invariant、同日 production health 聚合檢查與 PII-free 修復摘要；另以「02:30 後補登前一日請假」回歸測試覆蓋實際 producer 條件。

## 2026-07-17 — test: isolate local PHPUnit schemas per process (#1266)

開發備註：新增 `scripts/phpunit-isolated.sh`，每個 worktree／process 啟動只綁 loopback 的非特權 ephemeral MariaDB，使用唯一 `AllTrue_test_<suffix>_<nonce>` schema，原樣轉交 PHPUnit 參數並保留 exit code，結束或中斷時 drop／shutdown／清除 data directory。Wrapper 不需 sudo、Docker 或 production credential，且 fail-closed 拒絕 production DB 名稱、遠端 host 與自訂 PHPUnit config。

## 2026-07-17 — fix: repeated learning-review notification sync no longer returns 500 (#1264)

Fixed：主任或家長頁面同時刷新通知時，重複的待審評量提醒會安全合併為同一則，不再因唯一鍵競爭讓請求失敗。

開發備註：MySQL `REPEATABLE READ` 下，原 fallback 的 snapshot read 看不到另一請求剛提交的 `SourceKey`；改用鎖定 current read，且只處理 `notifications_sourcekey_unique` 的 1062。新增雙連線競爭測試與 PII-free recovery telemetry。

## 2026-07-17 — ops: make deployment auth smoke resilient and diagnostic (#1270)

Ops：部署後的帳密登入 smoke 現在只對網路錯誤、HTTP 408／425／429 與 5xx 做最多三次的短暫重試；401 等帳密錯誤與 2xx 無 token 會立即、精準地阻擋部署，不再把所有情況誤報成「登入成功但沒有 token」。兩層 smoke 共用同一個不洩漏回應內容或憑證的 token 解析與登入 helper。

開發備註：Presubmit 新增 deterministic smoke-auth contract，覆蓋既有四種 token response shape、503／transport recovery、401 不重試、2xx 無 token 不重試，以及診斷內容不得洩漏密碼或 response body。

## 2026-07-17 — ops: lock CI runner topology to isolated hosted jobs

Ops：所有直接執行的 workflow jobs 明確固定為 GitHub-hosted `ubuntu-latest`；唯一 delegated OSV job 固定 immutable commit。Presubmit 新增 topology contract，會阻擋未經 security/operations review 的 runner 或 reusable workflow 漂移。

開發備註：同步修正 Runbook、INDEX、offline merge SOP 與 regression lessons 的過時 WSL2 敘述；PHPUnit 每個 job 的 MySQL service container 隔離是目前並行安全邊界。

## 2026-07-17 — fix: classify surviving student sign-in orphans (#1262)

- Added PII-free scheduler health counts that distinguish student sign-in orphans whose `MDT` is at/before the verified nightly close from rows written afterward, plus an unclassified count when the execution evidence or timestamp is unavailable.
- Added regression coverage for historical sign-ins written after the nightly command and for rows already present before it.

> 格式：每條一行，分類 Added / Fixed / Changed / Security / Ops  
> 細節查 PR 說明或 `.cursor/plans/`  
> **版本公告（給老師／主任看的短卡）**：請寫入 `docs/STAFF_UPDATES.yml`（見 `GUIDE_STAFF_UPDATES.md`）。CHANGELOG 本檔是工程紀錄；`開發備註：` 行不會進草稿。  

> **閱讀**：依日期標題搜尋；**勿逐行通讀**。
>
> **滾動歸檔策略**（對齊 Keep a Changelog / 大型 repo 慣例）：主檔只保留**當月**，月初把上月移入 `archive/`。更早紀錄：
> - 2026-05：[`archive/CHANGELOG_ARCHIVE_2026-05.md`](archive/CHANGELOG_ARCHIVE_2026-05.md)
> - 2026-04（含更早）：[`archive/CHANGELOG_ARCHIVE_2026-04.md`](archive/CHANGELOG_ARCHIVE_2026-04.md)

---

## 2026-08-01 — feat: 跨分校學生／家長入口 pilot bridge

- **Added**：主任可人工確認學生身份關聯；家長可在同一入口切換「全部分校／指定分校」查看課表、評量、出缺勤、公告與帳務總覽，每筆資料標示來源分校。
- **Security**：關聯預設 `off`，依序支援 `readonly`／`actions`；姓名、手機與舊 LINE binding 不會自動合併，Invoice／Payment／收據／對帳仍維持分校邊界。
- **Fixed**：家長入口載入公開分校清單時，例外紀錄改用正式 Log facade，避免後端錯誤被二次轉成 500。
- **開發備註**：Expand-only bridge；保留 legacy `StudentID` 外鍵。詳見 [`MODULE_CROSS_CAMPUS_PARENT_PORTAL.md`](MODULE_CROSS_CAMPUS_PARENT_PORTAL.md)。

## 2026-07-17 — fix: 夜間對帳面板可讀、可分類，且不再假裝能一鍵改堂數

Fixed：系統管理員現在能在夜間對帳面板看到學生、科目、分校與異常原因摘要；修正 API 回傳包裝未拆開而導致整頁看似零資料的問題。移除實際不存在、也不符合資料修復核准流程的「重算」按鈕，面板明確維持唯讀。

開發備註：`reconcile:nightly` 沿用 `SessionDeductionService` 權威口徑分類原因，GitHub 排程證據只帶 PII-free 聚合數；姓名／分校僅在 super_admin API 請求時補上。任何計數器修復仍須另走備份、核准與回滾流程。

## 2026-07-16 — fix: 調課確認／成功改人話（跨行事曆與課程管理）

Fixed：調課送出前不再寫「原堂改期／新堂排入／課程編修追溯」；改為「原本→改為」人話確認。成功提示改為「學生＋科目＋原時段→新時段」。撞課名單不再出現「#學生編號」。課程管理與行事曆共用同一套說明與「查詢老師可補課時段」用語。

開發備註：擴充 `scheduleDisplay`（`formatRescheduleConfirmDialog`／`Success`／`ConflictStudents`／`humanizeRescheduleFailure`）；同步 CourseManagement／SmartCalendar／SessionEdit 三條調課路徑。未動 API。

## 2026-07-16 — fix: 排課失敗改人話（不再露出欄位名／HTTP）

Fixed：新增／排課失敗時不再顯示 `monthly_sessions`、`HTTP 500` 等工程用語；改為「請填寫本月預排堂數」或「請檢查學生、老師、日期與上課星期」。主任可直接知道要改哪一項，不會把系統錯誤當成自己操作失敗而亂重試。

開發備註：新增共用 `scheduleDisplay.js`（`formatScheduleErrorMessage`）；`universalSchedulerErrorMessage` 改為薄轉發。另加 `directorFacingIdLeak` 掃描，禁止 Vue 模板再寫 `課程 #{{`／`SC #{{`。

## 2026-07-16 — fix: 連假批次請假略過清單改學生＋科目（主任請假路徑）

Fixed：連假批次請假完成後，略過清單不再寫「課程 #數字」；改顯示學生姓名與科目（加上日期與原因）。主任可直接知道哪一堂要改用單堂請假，不必對照內部編號。

開發備註：Display only — `formatBulkLeaveSkippedLine`／`humanizeBulkLeaveSkipReason`；`BulkLeaveModal` 用已載入的 `courses` 對照，未動 bulk-leave API。

## 2026-07-16 — fix: 續報／帳本改使用者語言（主任任務路徑）

Fixed：續報／加購成功提示改為「學生＋科目＋堂數／日期」；帳本不再顯示 COURSE-／Payment #；可信度名單不再出現「學生 #」。主任完成續報與對帳時不必理解內部編號。

開發備註：Display only — `studentClassDisplay` 新增續報／帳本 formatter；改 CourseManagement／StudentsList／AccountingLedgerModal／DirectorDashboard／DuplicateSessionReviewPage。未動 API。

## 2026-07-16 — fix: 帳務結清畫面改顯示開課日／剩餘堂數（UXID-002）

Fixed：催繳／結案確認不再寫「課程 #數字」；改顯示開課日與剩餘堂數，「已有後續同科目課程」亦用人話。主任結案時不必理解內部編號。

開發備註：擴充 `studentClassDisplay.js`（`formatTuitionSettleSummary`／`formatTuitionNewerCourseHint`）；僅改 `TuitionCollectionPage.vue` 顯示層，未動 API／帳務邏輯。

## 2026-07-16 — fix: 重疊審核改顯示科目／老師／開課日（in-app #200）

Fixed：重疊課程審核不再用「SC #」當主標籤；改顯示科目、老師、開課日與堂數，主任不需理解內部編號即可選擇保留哪一側。SC 僅保留為小字技術識別。

開發備註：新增共用 `studentClassDisplay.js` formatter + unit／決策路徑測試；`DuplicateSessionReviewPage.vue` 改用 formatter。盤點文件 `docs/GUIDE_UX_INTERNAL_IDENTIFIER_AUDIT.md`（只盤點未全改）。不改 API／Trust Flow／telemetry。

## 2026-07-16 — fix: 可信度決策卡顯示學生姓名（in-app #200）

Fixed：主任儀表板「可信度」決策卡現在會直接標出涉及學生姓名；點「去審核重疊課」等按鈕會帶入該學生篩選，不用自己再找。

開發備註：`DirectorDashboard.vue` 新增 `trustPeopleSummary`／`trustDecisionTitle`；`DuplicateSessionReviewPage.vue` 讀 `alltrue_ops_trust_focus` 顯示篩選橫幅。

## 2026-07-16 — feat: POP Phase 1 catalog / policy / invariant / interfaces

- Added：`operations/catalog.yaml`、`operations/policies/default.yaml`、`operations/invariants/session-pack@1.0.0.yaml`、`backend/app/Operations/Contracts/*`、`scripts/pop-fitness-check.mjs`
- Changed：`docs/INDEX.md` 新增 POP 服務目錄入口（catalog/ADR 分拆治理）
- 開發備註：Phase 1 為 read-only foundation；不含 production execute。

## 2026-07-16 — docs: Measure 唯一下一步與 #173／Issues 分流

- **Changed**：正式回報唯一下一步改為「自 2026-07-17 凍結 Trust 實驗面、收集有效 telemetry、至少一次真實主任無教練驗收」；#173 資料修正與 Issues 權限為分流。
- 開發備註：Issues 僅申請 AllTrue_System 的 Issues Read & write；不重設 Day0。

## 2026-07-16 — fix: 續報後重疊堂改為可稽核「被取代」（in-app #173 B）

- **Fixed**：續報新課後同一時段兩筆正式堂，舊課那筆改標為被新課取代、不再重複計費；原評量保留不動，帳務與剩餘堂數不變。
- 開發備註：`session_corrections` + `repair:supersede-renewal-session --case=173`；PCR `docs/runbooks/173-supersede-b-pcr.md`。不改 Trust／Day0。

## 2026-07-16 — docs: #173 決策包 + Day0 表述澄清 + Issues 403 診斷

- **Changed**：釐清正式 Day0 仍為 2026-07-17（7/16 全日排除）；in-app #173 產出唯讀 A/B 決策包（不改歷史資料）；記錄 GitHub Issues API 403 最小權限修復方式。
- 開發備註：不改 E-OPS-TRUST 實驗面；CEO 回報禁用學生姓名。

## 2026-07-16 — fix: 信任決策卡可導到具體錯誤對象（in-app #200）

- **Fixed**：主任點決策卡不再只進空白行事曆／課程管理——重疊堂改走「重疊課程審核」，堂數對不起來／休眠會帶出可點名單並預填搜尋。
- 開發備註：會改 CTA／入口，Measure Day0 誠實重設（見 `.cursor/plans/ops_trust_measure_iterate_2026-07-15.md`）。

## 2026-07-16 — docs: 信任決策中心量測分母與樣本有效性（v3）

- **Changed**：明確各 Outcome 分母（有效任務、到期應處理 Critical、actionable_at、bypass session）；樣本不足不得 Keep／Kill，滿 14 日仍不足則偏向關閉或縮小入口。
- 開發備註：僅更新 `.cursor/plans/ops_trust_measure_iterate_2026-07-15.md` 與 Compare 模板；無產品功能新增。

## 2026-07-16 — fix: 信任決策中心量測口徑修正（Day0=7/17）

- **Changed**：正式觀察改從 7/17 完整營業日起算；決策卡曝光改為真正進畫面才計算、同日同人去重；合法休眠保留不再把分數卡死無法變綠；遙測不再帶可連結學生編號。
- 開發備註：定義見 `.cursor/plans/ops_trust_measure_iterate_2026-07-15.md` v2；CTR 僅診斷、dormant count 不是成功條件。

## 2026-07-16 — feat: 主任信任決策中心進入量測閉環（名單＋遙測）

- **Added**：主任總覽決策卡可展開「要處理誰／為什麼／下一步」名單，點人名可直接進課程管理並帶入搜尋；Critical 分數採硬門檻封頂，休眠保留不當系統故障。
- 開發備註：最小遙測事件（score／曝光／點擊／繞行）寫入 adoption daily log（已 sanitize）；Hypothesis 成功門檻見 `.cursor/plans/ops_trust_measure_iterate_2026-07-15.md`。本輪不以部署成功代表產品成功。

## 2026-07-12 — feat: 評量頁新增「只看已填」篩選

- **Added**：評量／學習紀錄頁新增「只看已填」篩選（就在「只看未填」旁邊，兩者互斥）——主任／管理者可一鍵只檢視已填寫評量正文的紀錄，方便回顧已完成的評量內容（in-app #199）。
- 開發備註：純前端顯示篩選（依 `hasLearningRecordBody` 過濾），無 API／資料／權限變更；additive、非破壞性；PR #1194。

## 2026-07-11 — feat: 主任總覽新增今日優先處理

- **Added**：主任總覽會從既有待辦中整理最需要先處理的三項，說明逾期、收款、點名、補課、評量或家長回饋為何需要留意，並可直接前往處理畫面。
- 開發備註：純前端排序 helper `directorPriorityRisks`，只使用主任頁既有且已分校授權的 aggregate counts；不新增 API、不改繳費或排課規則。

## 2026-07-11 — fix: 課程改排時，代課／例外時段不再被連續搬移

- **Fixed**：修改固定上課日並批次重排未來堂次時，相關代課或例外排程會跟隨各自堂次移動一次，不再因日期重疊而被後續堂次再次搬走。
- 開發備註：contract reflow 在任何寫入前 snapshot `schedules.id`，再於同一 transaction 同步 `ClassSession`、active `LearningRecord` 與 schedule anchor；回歸 `RealignReflowTwoPhaseTest`。

## 2026-07-10 — feat: 每日商業智能摘要（AI-native ops phase 0）

- **Added**：`ops:business-digest`（每日 04:10 唯讀）——營收風險（未排程的預付堂 × 費率）、留存風險（近 14 天無課的在籍生）、資料品質異常、未來 7 天課量,每早自動量化營運健康度。
- 開發備註：純唯讀,計算抽到 `BusinessDigestService`（ADR-003）;`docs/POLICY_AI_NATIVE_ROADMAP.md` 定義 Phase 0-5（BI dashboard → 異常偵測 → 留存/營收智能 → AI 輔助行政 → 自動化工程維運）。此為 AI-native 演進的 metric 底座。

## 2026-07-10 — fix: 評量「無法填寫」缺口回填 + 夜間自動任務正式啟用

- **Fixed**：部分已上課堂次因系統缺漏無法填寫評量的問題已修復（回填 268 筆待填評量；老師端即可正常填寫）。
- **Ops**：production Laravel scheduler 從未有 driver（#1127 事故）：`schedule:run` cron 已布線，8 個夜間任務（對帳/孤兒清理/stranded 稽核/LR 回填/復現閘門）自今晚起實際執行；`pi-health` 新增 scheduler 心跳 critical（R68）。
- 開發備註：`.env` 權限 644→640；PR #956 路由存儲上線（append-only 版本化，行為零變更）；merge train 收尾 11 個 PR。

## 2026-07-09 — fix: 重複堂次清理完成 + 加課/跨約重複資料修正（PCR-R2 執行）

- **Fixed**：課表與評量中「同一堂課出現兩筆」的重複堂次已全部清理（21 組），評量「未填」誤提醒與收據日期錯位一併修正（陳品承 6/13、6/20；吳夏妍 5/14）。
- 開發備註：PCR-2026-07-09-957-D1-R2 A1+B 獲 CEO GO 後執行；audit intra=0；snapshot + 表級備份齊備；執行紀錄見 `docs/runbooks/957-d1-r2-execution-record.md`。
- 開發備註：unique slot index 重設計為 active-only（`ActiveSlotFlag` generated column，PR #1121）；placeholder PCR 取消。deploy migration 失敗不再被吞（PR #1120，R67）。
- 開發備註：治理批次——issues 94→81（含證據關閉）；remote branches 35→22（tag-then-delete 可逆）；in-app #171/#172/#175/#189/#191/#195 收尾。

## 2026-07-09 — fix: #957 D1 cleanup scope aligned with audit (PCR-R2)

- **Fixed**：`classsession:cleanup-intra-duplicates` 僅刪 Type-A active conflicts（與 audit 同語意）；cancelled placeholder 改為分析 only。
- **Added**：`ClassSessionIntraDuplicateFinder`、regression test `ClassSessionAuditCleanupScopeAlignmentTest`；PCR-R2 runbook。
- 開發備註：2026-07-09 preflight STOP（806 vs 21 組）；production freeze 維持至 CEO GO `PCR-2026-07-09-957-D1-R2`。

## 2026-07-09 — docs: #190 對帳 + #189/#191 dry-run + #957 D1 設計

- **Changed**：`190-reconciliation-report`（6 筆 SC 逐筆對帳、Invoice #690/#691 建議 amend）；`189-191-dryrun-report`（72 組 before/after）；`957-d1-sprint-design`（unique index migration）。
- 開發備註：production 唯讀稽核 2026-07-09；零寫入；洪子勛 Payment void 2998/0 已查證。

## 2026-07-08 — docs: Reliability Engineering — bug closure gate + #190 historical audit

- **Changed**：新增 `docs/GUIDE_BUG_CLOSURE_GATE.md`（六項關閉閘門）；`docs/incidents/190-historical-billing-repair-plan.md`（週日 0 元歷史帳務唯讀 audit，6 筆合約）；`189-191` 計畫補 §7 dry-run audit 結果。
- 開發備註：T0 docs-only；production 唯讀查詢已執行，**零寫入**；#190/#194/#196 code fix 不重開。

## 2026-07-08 — docs: in-app #189/#191 跨約重複堂次資料修復草案

- **Changed**：新增 `docs/incidents/189-191-data-repair-plan.md`（影響分析、唯讀偵測 SQL、修復策略比較、draft migration 規格）。
- 開發備註：**禁止未經 CEO 核准前於 production 執行任何寫入**；長期修復仍依 Epic #957。

## 2026-07-08 — docs: AllTrue Agent Engineering System v1

- **Changed**：新增 `docs/GUIDE_ALLTRUE_AGENT_SYSTEM_V1.md`、`.cursor/skills/alltrue-*`（除錯／測試／發布／安全／code review）與 `docs/GUIDE_AGENT_SKILLS.md` 上游評估。
- 開發備註：T0 docs-only；不整包安裝 addyosmani/agent-skills；INDEX + AGENTS.md 導航更新。

## 2026-07-08 — fix: 請假後課程詳情不再多畫出不存在的 16-18 堂次

Fixed：登記請假後，課程詳情的「上課日期」若出現半透明的錯誤時段（例如週日 10-12 的課卻多出一個 16-18 請假），已修正；現在只會顯示真實堂次。

開發備註：session-dates API 的 `collectMaterializedFromRows` 把 `leave` 堂次排除在 materialized 之外，同日又從契約推算 projected slot；POST body 的 StudentClass select 缺 `time` 欄位 → `resolveSlotTimesForCourseDate` fallback 16:00 → 前端半透明 chip 顯示幽靈 16-18 請假（in-app #196／GitHub #1101，劉芯岑案例）。修正 = leave 納入 materialized + POST select 補齊 time/duration 欄位。回歸 `SessionProjectionLeaveGhostTest`。

## 2026-07-08 — fix: 家長請假「審核中」的堂次，出缺勤與課表顯示不再互相矛盾

Fixed：家長送出請假但主任尚未審核時，這堂課在「出缺勤管理」會消失、卻在「課表與評量」被列成待填評量。已統一：兩邊都會顯示「請假(待審)」，不需點名也不需填評量；若審核退回，堂次會自動恢復待點名/待填。

開發備註：ParentPortal 請假流程只把 `ClassSession.Status` 設為 `leave_requested`（不建 StudentSingIn 列）；出缺勤管理把該狀態整列過濾掉、`sessionConsistency` 與 `LearningRecord::scopeExcludeLeaveSessionPendingReview` 只認 `leave`/`excused` → 兩畫面認定分歧（in-app #194／GitHub #1099，陳品承 7/4 案例）。修正 = `leave_requested` 進 NON_FILLABLE + 統一 label「請假(待審)」+ attendance statusRows 顯示 + 後端 scope 補 session-status 分支。回歸測試 `sessionConsistency.test.js` + `LearningRecordLeaveExclusionTest::test_pending_lr_on_leave_requested_session_is_excluded`。


## 2026-07-08 — fix: 週日課程的月結金額不再算成 0 元

Fixed：排在「週日」的月結課程，續約時系統算不出堂數，繳費金額會顯示 0 元（新店 6/30 回報的繳費通知問題）；現已修正，週日堂次會正確計入金額與課表。

開發備註：`buildSessionsFromWeeklySchedule` 用 Carbon `dayOfWeek`（0=日）比對 ISO 星期（7=日）的 slot，週一～六兩套慣例值相同、唯獨週日永不匹配 → 週日 date-mode 課程生成 0 堂 → renew-monthly 算出 SessionCount=0/Charge=0 → NT$0 Invoice（in-app #190／GitHub #1096，洪子勛案例）。修正 = slot weekday 正規化 0→7 後以 `dayOfWeekIso` 比對（兩套慣例都吃）；Import 與 shadow ScheduleResolver mirror 同步；`ScheduleSlots` 入庫一律存 ISO。回歸測試 `WeeklyScheduleSundayBuilderTest` + `MonthlyRenewTest::test_renew_monthly_sunday_course_computes_sessions_and_charge`。


## 2026-07-08 — fix: 課程資料欄位對齊，避免課程匯出／新增課程隨機失敗

Fixed：修正一個內部資料欄位不一致問題，該問題可能讓「課程匯出」或部分「新增課程」流程出現錯誤，現已對齊。

開發備註：schema drift 對齊 — `StudentClass.RoomID` 已於 2026-06-30 在 production 被手動 migration 移除（batch 107/108，出自未合併的 `815ad275`），但 main 程式碼仍讀寫該欄位（Export 明確 SELECT、StudentClassController/CoursePackageController/Import 寫入、Model fillable）。本次把兩個 migration 檔＋後端 RoomID 移除 port 回 main（不含 `815ad275` 的行事曆前端與 #1087/#1079 回退部分），Export 改 SELECT `room_id` 保持 CSV 欄位對齊；121 個測試檔的 RoomID payload 一併清除；新增 `StudentClassRoomIdSchemaDriftTest` 鎖定 CI schema == production schema。

## 2026-07-08 — ops: 部署管線硬校驗 — 杜絕「回報成功但上的是舊版」

Ops：部署流程加上目標版本硬校驗：抓取失敗立即中止並亮紅，部署完成的版本必須等於 CI 驗證過的那一版。

開發備註：Pi repo config 被誤寫 `http.sslbackend=schannel`（Linux git 不支援）→ `git fetch` fatal；deploy step 無 `-e` 吞錯、`reset --hard origin/main` 落在 stale tracking ref，smoke 照樣綠。修正 = deploy.yml [1/7] self-heal unset + fetch fail-fast + `reset --hard $workflow_run.head_sha` + HEAD 校驗（§R62）。

## 2026-07-08 — fix: 同時段不同學生的堂次不再被合併吃掉（課程管理／班級行事曆）

Fixed：一對二／一對三同一時段的不同學生，畫面上會被合併成一筆導致其中一位漏顯（例如班級行事曆只看得到其中一位），已修正。

開發備註：`classSessionsApi.mergeSessionViewModels` slot key 原本只有 `(date,startTime)`，整包 payload 先合併再分課程 → 跨學生互吞（Phase C1 refactor `5bfaf4bd` 引入；R49/#187/#188 共享時段家族；in-app #182「仍存在」真兇）。key 補課程身分 + unkeyable 不合併；新增 `classSessionsApi.test.js` 掛入 `test:calendar`。

## 2026-06-29 — fix: ClassSession projection API — calendar completeness-safe (no pagination)

- **Fixed**：新增 `GET /api/v1/class-sessions/projection`（`api_kind: projection`, `completeness: full`），行事曆改走此端點，杜絕 list API `per_page=2000` 靜默截斷導致新莊等分校缺課。
- 開發備註：`ClassSessionProjectionTest`；SOP 見 `docs/GUIDE_PROJECTION_INTEGRITY.md`。

## 2026-06-29 — fix: calendar ClassSession branch projection aligns with course room campus

- **Fixed**：行事曆週檢視改以 `branch_id + 日期區間` 載入全部 `ClassSession`（不再綁定已篩選課程 ID）；分校篩選與課程管理一致（有教室用 `rooms.campus_id`，無教室用學生 `CampusID`），修復新莊等分校「出缺勤有課、行事曆缺課」。
- 開發備註：`ClassSessionBranchCampusFilterTest`；`useCalendarDataLoad` 補 session-only 課程 stub 供 `mergeWeekCalendarOccurrences` materialized pass。

## 2026-06-29 — security: untrack legacy PII dumps + backend-local stub

- **Security**：`git rm --cached` 19 個 `backups/**/*.sql.gz`（production PII，runtime 備份在 Pi `/home/admin/backups/`）與整個 `backend-local/`（Windows mock，`.gitignore` 早已排除但仍被追蹤）— 清除 Dependabot `path-to-regexp`/`qs` alerts
- 開發備註：secret-scanning #1（`AllTrue (3).sql` 歷史 blob）仍需 filter-repo + BotFather revoke（#1025）

## 2026-06-29 — security: npm 依賴修補 + composer audit gate 修正

- **Security**：前端升級 `vite` 6.4.3、`@vitejs/plugin-vue` 6.x（修補 GHSA path traversal / esbuild dev-server）；`jsdom` 連帶 `undici` 7.28.0；`npm audit --audit-level=high` 清零
- **Security**：CI `composer audit` 解析 bug 已定位（advisory dict 漏掃 HIGH）— 修正待 TD-014 Laravel upgrade 後一併上線，避免在 framework 未修補前誤擋 merge
- **Security**：`guzzlehttp/guzzle` constraint 升至 `^7.12.1`（lock 已 7.12.3）
- 開發備註：GitHub secret-scanning #1（Telegram bot token）與 Laravel 8→12 major upgrade 仍為 open blocker（#1025、TD-014）

## 2026-06-28 — fix: 班級行事曆漏顯已調課堂次 + LINE 綁定讀家長手機

Fixed：班級行事曆若週次篩選暫時隱藏某課程，已實際存在的堂次仍會顯示，與課程管理詳情一致。LINE 官方帳號「綁定 姓名 手機」改與家長入口相同，優先比對「家長手機」欄位。

開發備註：`calendarOccurrenceMerge.js` materialized pass 改掃 `allCourses`（#1035 / in-app #182–184）；新增 `StudentContactPhone` + `LineWebhookBindingTest`（§R10 LINE bind 對齊）。PR #1036、#1037。

## 2026-06-28 — security: secret exposure remediation (HEAD cleanup + webhook hardening)

- **Security:** Remove tracked `.env.monitor` and `.cursor/projects/**` from git; add `.env.monitor.example` and [`SECURITY_CREDENTIAL_ROTATION.md`](SECURITY_CREDENTIAL_ROTATION.md).
- **Security:** Mask campus swipe/Telegram secrets in `AdminCampusController` API (#975).
- **Security:** Telegram webhook `X-Telegram-Bot-Api-Secret-Token` validation (#1021) + `TelegramWebhookSecret` column.
- **Ops:** Add `scripts/security-filter-repo.sh` and `scripts/security-gitleaks-audit.sh` for pre-public history purge.

---

- **Changed**：後端 `ClassSessionMaterializationService::upsertSlot` 為唯一 production 寫入路徑；`session-dates` / `class-sessions` API 分開回傳 materialized 與 projected。
- **Changed**：前端 `classSessionsApi.js` 統一 `SessionViewModel`；課程管理、評量頁、行事曆 adapter 消費同一模型（含 legacy 欄位別名）。
- **Added**：`classsession:audit-duplicates` 唯讀稽核指令。

## 2026-06-27 — fix(course-mgmt): 課程重疊建立改走 in-app 強制建立視窗，不再卡死路 (in-app #174)

新增固定課程時，若和學生既有「同一位老師、同科目、上課日期重疊」的課程衝突，過去會跳出提示叫你「勾選強制建立」，但畫面上根本沒有那個勾選框，等於卡死路。現在改成跳出視窗，讓你選「加購堂數、延續原課程」或「我知道，仍要新增課程」。

開發備註：#805 後端新增 `overlapping_active_course` 409，但前端 `universalSchedulerApi.js` 只把 `duplicate_active_course` 設成 `isDuplicateCourse`，重疊碼落到 `UniversalClassScheduler.vue` 的原生 `alert(err.message)` → 無 force 入口。抽出無相依純函式 `isDuplicateInterceptCode()`（node 測試可直接 import）讓兩碼都導向攔截 modal；回歸測試加在 `universalSchedulerApi.test.js`（build 腳本會跑）。**Ops 例外**：GitHub Actions minutes 用完期間，依 `OPERATIONS_RUNBOOK.md` §139 走緊急手動前端部署——本機 `npm run build` 綠 → `rsync dist_build` → Pi `copy-to-backend.cjs`（含 index/asset 一致性 guard + OPcache flush）→ version `acf1251`，已驗 health ok、`assets/*.js` 皆 200 `text/javascript`、served chunk 含修正後 `isDuplicateInterceptCode`。**未動 Pi git／storage**（只覆蓋 `backend/public` 前端 bundle，已先備份至 `backups/emergency/pre174_*`）。待 Actions 恢復補 PR（branch `fix/course-overlap-force-create`）回 main。GitHub #931。

## 2026-06-21 — fix(parent): 家長 LINE 自動登入（共用網域分校）＋ 共用方案堂數顯示

家長從 LINE 開啟入口時，會依「所屬分校」載入正確的 LINE 入口，自動登入更穩定；若帳號尚未綁定，畫面會清楚告訴你「請用學生姓名＋手機登入，或先在 LINE 完成綁定」，不再卡在「正在自動登入…」又同時跳紅字錯誤的矛盾畫面。另外，多科共用同一方案堂數時，每一科會標示「共用方案」並顯示同一份共用剩餘堂數（扣堂一起計），剩餘總數不再被各科重複加總。

開發備註：**Bug 1（LINE 登入）**根因＝13 新莊中平與 15 大安共用 `daan.lifenet.com.tw`，但各自是不同 LINE Login channel／provider（同一學生在不同分校的 `line_user_id` 不同已於 prod 證實）。`resolveLiff()` 純 host 比對只回「第一個」分校（id 升序＝13）的 LIFF，導致 15 大安家長（19 筆綁定）拿到 13 的 LIFF → `getProfile().userId` 屬不同 provider → `loginWithLine` 查無綁定 404。修法：入口連結本就帶 `campus_id`（`LineWebhookController::getPortalUrl`），`resolveLiff` 改**優先用 `campus_id`** 定位該分校 LIFF；前端 `onMounted` 以 `campus_id` 解析 LIFF 覆蓋 build-time 預設。前端另把「自動登入失敗」從矛盾文案改為明確綁定/手動登入指引（`autoLineNotBound`）。**Bug 2（共用方案）**：家長 dashboard 對 `PackageID>0` 成員改以 `course_packages` 池子（remaining/used/total）為準，`sessionMetrics` 與顯示聚合每池只算一次，新增 `is_package`/`package_*` 欄位前端標示「共用方案」。新增 ParentPortalSharedPackageTest(2)、ParentPortalResolveLiffTest(2)；既有 Parent/Package/Session/StudentClass 315 綠、PHPStan clean。對應 in-app 家族 #158/#162。**不動收款/invoice/費率**，僅顯示與登入解析。

## 2026-06-14 — Ops: GitHub / SRE roadmap 對標大公司治理

開發備註：新增並整理 AllTrue Engineering Roadmap：M4 生產安全與流程自動化（#867–873）、M5 UI/UX 質感與可讀性（#866/#857–865）、M6 GitHub 治理與協作成熟度（#875–880）、M7 系統維護與 SRE 營運成熟度（#881–886）。Project board 已建立並連到 repo；`docs/SOP_MATURITY.md` 補上 Actions minutes 用完時的工作分流、GitHub Environments/CODEOWNERS/Project automation/release traceability/security advisory/ruleset 缺口，以及 PITR、Full server DR、incident response、observability、capacity management、maintenance window/status page 等維運缺口。純治理/文件/issue 規劃，無 production code 變更。

開發備註：補充 M8 資安/隱私/合規成熟度（#887–892：host hardening、IAM access review、PII inventory/retention、sensitive audit coverage、Threat modeling/ASVS、vendor risk register）與 M9 工作流程/組織營運 SOP（#893–898：service catalog/RACI、SOP review cadence、support SLA metrics、ADR/RFC、release train、AI/human onboarding）。已加入 Roadmap Project 並更新 `docs/SOP_MATURITY.md`。純治理/文件/issue 規劃，無 production code 變更。

開發備註：補上「軟體公司跨部門 operating model」規劃，依 IT / SRE / Security / Engineering / QA / Product / Support / Data / Legal / Docs 視角新增 #899–908（RFID/device inventory、weekly ops review、data quality checks、security exception register、privacy request SOP、technical health scorecard、role-based QA matrix、product health review、public reply macro library、quarterly roadmap review）。已加入 Roadmap Project 並更新 `docs/SOP_MATURITY.md`。純治理/文件/issue 規劃，無 production code 變更。

開發備註：依老師/主任/家長三種正式使用者視角做唯讀體驗審查，新增 #909–912：老師端 System Trust 分眾文案 bug（in-app #167，attachment #112）、老師首頁下一步說明、主任 cockpit drill-down/explanation layer、家長狀態時間線與主動通知。已加入 Roadmap Project 並更新 `docs/SOP_MATURITY.md`。未改 in-app 狀態或留言、未動 production 資料。

開發備註：完成 GitHub milestone hygiene：關閉舊 Phase 1/2/3 milestones（#1–#3，皆 0 open），M1/M2/M3（#4–#6）維持已關閉；active roadmap 收斂為 M4–M9。將未歸檔的 in-app UX bugs #851/#855 併入 M5，避免「no milestone」漏追。同步更新 `docs/SOP_MATURITY.md`。純 GitHub metadata / docs 整理，不耗 Actions minutes。

開發備註：Actions-down 高價值工作交接（#907 / #851 / #855 / #909）。新增 `docs/GUIDE_SUPPORT_REPLY_MACROS.md`（10 個 in-app bug 公開回覆白話 macro，含公開留言＋內部備註＋禁用詞檢查＋對應狀態機，對齊 §3.8）並補 `docs/INDEX.md` 入口；對 #851/#855/#909 補 triage（白話問題＋驗收條件＋blocked-by-deploy，唯讀未改 in-app 狀態）；補 metadata（#851/#855 priority+area+status:blocked，#867/#870 status:blocked）；`docs/SOP_MATURITY.md` 補每 milestone Top 3、狀態分類與「CI 凍結時工程師 playbook」。純 docs / GitHub metadata，無 production code 變更。

## 2026-06-14 — feat(attendance): 出缺席新增試聽/輔導/值班/補課/停課狀態 (#765)

點名時除了到班/遲到/請假/缺席，新增「試聽有到、試聽未到、輔導有到、輔導未到、值班、補課、停課」等狀態（補登/詳細選單可選）。各狀態自動套用正確的扣堂與計薪規則：補課會扣堂並計薪、值班計薪但不扣堂、試聽/輔導不扣堂也不計薪、停課皆不算。既有四狀態行為完全不變。

開發備註：抽出 `App\Support\AttendanceStatus` 單一真相 registry（label/deductible/payable/requires_log/session_status），扣堂集（AttendanceController）與計薪集（FinanceController payroll，值班 duty 計薪不扣堂為唯一刻意差異）、session 狀態映射（AttendanceEffectsService，makeup→attended）全部路由到 registry。AttendanceStatusSemanticsTest 15 綠釘住競品表 + 241 attendance/payroll/finance 回歸全綠（零回歸）。requires_log 元資料供 #768 漏交追蹤。PR #837；GitHub #765。

## 2026-06-14 — feat(schedule): 批次排課 CSV 匯入前衝突檢查 (#770)

提供「排課衝突預檢」：上傳批次排課 CSV，系統在寫入前逐列標出「同時段同教室／同老師」衝突（紅）與「學生同時段已有課」警告（黃），避免撞堂撞教室。

開發備註：`POST /api/v1/schedule-import/preview`（純讀取）：解析 CSV，對 DB 既有非取消堂次 + 同檔對稱檢測時間重疊衝突 + 格式驗證。ScheduleImportPreviewTest 2 綠。原子 execute（實際建課）因扁平 CSV 缺計費欄位另行設計。PR #839；GitHub #770。另：`GET /api/v1/teaching-logs/missing`（#768）回傳各老師需教學日誌但逾 24h 未填的堂次清單（requires_log + 無 LearningRecord），PR #838。

## 2026-06-14 — style(ui): 全站 Toast 統一 + UI 去 AI 化逐頁/元件治理 (#687 系列)

成功/錯誤/復原提示改為全站一致的統一 Toast（白底 + 左語義色條），不再各頁樣式不一。同時完成「UI 去 AI 化」逐頁與共用元件治理（金流/老師/出缺勤/儀表板/行事曆等頁 + 表單/Modal/排課器等元件），移除硬編色票改用設計系統 token，介面更統一專業。

開發備註：純視覺、零行為變更（HSL codemod 僅作用 `<style>`+inline，計算 byte-identical）。新增 `useToast`/`AtToast`（#708）與 `AtInput/AtSelect/AtTextarea/AtField`（#702）設計系統元件。逐頁/元件 PR #820–#849；hex 大幅下降。GitHub #687/#693/#694/#695/#696/#699/#700/#701/#702/#703/#704/#708。

## 2026-06-13 — fix(schedule): 建課偵測「同生同科同師日期重疊」防重複排課 (#805)

主任建立課程時，若該學生已有「同科目、同老師、上課期間重疊」的進行中課程（常見於續報新期起始日早於舊期結束），系統會先提醒，避免兩期在重疊週各排一堂、造成點名名單同一時段重複出現。可改用「加購堂數」延續原課程，或把新課起始日改到舊課結束之後；確定要建立仍可勾選強制建立。

開發備註：`EnrollmentService::store()` 既有 `duplicate_active_course`（同科同型）外，新增 `overlapping_active_course`（同 StudentID+SubjectID+TeacherID 且 StartDate/EndDate 區間重疊，跨 class_type 亦偵測），回 409 + 重疊明細，`force=true` 可覆蓋。日期來源涵蓋 confirmed/future_dates 與 session_plan。對應 in-app #161／GitHub #805／復發家族 F1「重疊續報」變體。OverlappingCourseGuardTest 2 綠。資料修正（林立晴 SC#1684）另行處理。

## 2026-06-13 — security(pin): 老師頁 PII 後端欄位級遮罩 (TD-066)

開發備註：補上 #769 老師管理頁的後端 PII 邊界。`GET /teachers`／`/profiles` 為多頁共用端點（CourseManagement／StudentsList／LearningRecords 下拉復用），無法整路掛 require_pin。改抽 `App\Support\PinGate::isUnlocked()` 單一謂詞（super_admin／未設 PIN／token 已驗證 → 通過），`RequirePin` 改委派它（行為不變、去重），`ProfileController::index` 三個輸出點在未通過時遮罩老師 `phone／line_id／rfid／rfid_by_branch`。soft：未設 PIN 者零回歸；下拉頁本就不讀 PII 故無感。TeacherPiiPinRedactionTest 3 綠 + PinVerificationTest 14 綠；PHPStan baseline 納入 PinGate 的 AuthToken::where magic（零刪除）。TD-066 結案。計畫 `.cursor/plans/td066_teacher_pii_pin_2026-06-13.md`。

## 2026-06-13 — security(pin): 敏感頁 PIN 二次驗證前端 gate + 路由強制 (#769 Phase B/C)

開發備註：接續 Phase A，完成前端覆蓋層與後端強制（**soft，零回歸**）。**D1 soft**（未設 PIN 的主任可「暫不啟用，直接進入」）／**D2** 受保護頁＝兼職薪資、帳務中心、當月學收、老師管理／**D3** super_admin 不納管。Phase B：`PinLockModal.vue` 全螢幕覆蓋（設計系統 token、無 emoji；set／verify／locked／reset 四態、4–6 位數字、Enter 送出），`App.vue` `pinModalActive` gate 擋住 4 頁直到解鎖、10 分鐘解鎖 TTL、閒置 5 分鐘 + 切分頁 60 秒自動鎖（`POST /pin/lock`）；純判定抽到 `lib/pinGate.js`（15 個 node 測試，接進 build 鏈）。Phase C：`RequirePin` 經 `auth_role` 放行 super_admin（mirror `RequireRole`）；`require_pin` 掛於受保護頁**專屬**敏感端點（`finance/parttime-payroll*`、`finance/teacher-payroll`、`part-time-rate-cards*`、`finance/branch-monthly-tuition*`、`accounting/payments*`、`accounting/settled-courses`），**刻意不掛**共享端點（`teachers`／`student-classes`／`alerts/tuition`，避免誤傷已設 PIN 主任）；router 內省測試守住「該掛有掛、共享沒掛」。PinVerificationTest 14 綠、PHPStan clean。PR #815／#816；GitHub #769。老師頁 PII 後端邊界因端點共享延後 → TD-066。

## 2026-06-13 — security(pin): 敏感頁 PIN 二次驗證後端基建 (#769 Phase A)

開發備註：為薪資／財務／教師個資敏感頁的 PIN 二次驗證鋪設後端 primitives，**零行為變更**（未掛任何受保護路由，未設 PIN 者敏感 API 照舊可用）。新增可逆 migration（`User.pin_hash／pin_failed_attempts／pin_locked_until／pin_set_at`、`auth_tokens.pin_verified_until`，皆 nullable）、`PinVerificationController`（status／set／verify／reset／lock）、`RequirePin` middleware（soft：未設 PIN 放行，已設未解鎖回 423 `pin_required`）、Kernel alias `require_pin`、`me/pin/*` 路由（含 per-IP throttle）。失敗計數／鎖定一律走 DB（避開事故 E 的 file cache owner 污染）；解鎖狀態綁 `AuthToken` session，登出即失效。弱碼黑名單 + bcrypt 雜湊，回應不含 hash／attempts，429／423 generic。PHPUnit 12 綠涵蓋 AC1–AC8。PHPStan baseline 為新 Eloquent magic props 重產（619→624 distinct，零刪除）。PR #812；GitHub #769。Phase B（`PinLockModal.vue` 前端覆蓋層 + 自動鎖）／ Phase C（受保護路由掛 `require_pin` soft）後續，需 UX 驗收與 D1–D3 拍板。

## 2026-06-13 — fix(perf): 行事曆載入大幅加速 (#804)

行事曆（含跨分校、整月視窗）載入原本在資料較多時要數秒到數十秒，現已調整為約 0.1 秒內完成，主任／老師開行事曆會明顯變快。

開發備註：production EXPLAIN/ANALYZE 確認瓶頸為 `ClassSessionController::index()` 的 `si`（最新簽到）derived table 因 `StudentSingIn` 缺 `ClassSessionID` 索引被 access=ALL 全表掃描（r_loops≈4471 × ~4609 列 ≈ 2060 萬列，全 campus 視窗 ~33.5s）；對照 `LearningRecord` 有對應唯一索引故走 ref。補 `StudentSingIn(ClassSessionID, id)` 非唯一索引後 33.5s→~0.1s（si ALL→ref，部署後 ANALYZE 驗證）。純索引、byte-identical。候選「缺 SessionDate 索引」經 EXPLAIN 否證（日期範圍已由 `cs_scid_sessiondate_idx` 處理）。PR #810；GitHub #804；in-app #160。附 revert-proof schema guard 測試。

## 2026-06-13 — fix(audit): 排課稽核日誌實際生效 (#766 補修, #784)

主任端的「排課稽核日誌」（誰在何時建立／修改／刪除課堂）先前因技術問題完全沒有寫入、且依分校查詢一律為空；現已修正，會正確記錄並可在主任端依分校／日期查詢。系統自動產生的行事曆投影堂次不列入稽核（只記真人操作）。

開發備註：三個 root cause —（1）`AppServiceProvider` 在 `DatabaseServiceProvider` 之前註冊，`boot()` 時 Eloquent dispatcher 尚未綁定，`ClassSession::observe()` 靜默 no-op → 改用 `app->booted()` 延遲註冊；（2）`branchId()` 讀不存在的 `StudentClass->BranchID` 導致 `branch_id` 恆為 null → 改走 `StudentClass->Student->CampusID`；（3）observer 生效後對 `projected-*`／backfill 系統堂次造成行事曆熱路徑 N+1 → 依 `Note` marker 略過。PR #784；GitHub #766；本地 MySQL 全測 1184 綠 / PHPStan 綠。另記 TD-065（`NotificationObserver` LINE 推播疑同源失效，未在本次 scope 處理）。

## 2026-06-13 — fix(billing): 課程總費用不再被錯誤舊差額卡死（#798）

課程總費用與「每堂費用 × 堂數」對不上、又沒有單堂時間調整紀錄時，重新儲存費率即會重算為正確金額，不會再被舊的錯誤數字永遠拉回（新店分校張同學案例，金額已同步修正）。

開發備註：`StudentClassController::update()` preservedDelta 改為僅在存在 `ClassSession.session_charge` 調整時保留；PR #801；GitHub #798；in-app #159；一次性資料修正 SC#422 8000→8800（CEO 批准）。復發家族 F7。

## 2026-06-13 — fix(billing): 改「未繳費」遇收款紀錄改為明確提示（#799）

課程有收款入帳紀錄時，把繳費狀態改成「未繳費」不再悄悄跳回「已繳費」：系統會直接說明哪一天已有收款、請先到收費頁作廢，避免主任白改好幾次。

開發備註：後端 409 `payment_record_locked`（含金額/日期 warnings，涵蓋 payment_status 與清空 paid_at 兩路徑）；CourseManagement／StudentsList 移除「API 失敗仍本地假成功」死碼；PR #802；GitHub #799；in-app #158。復發家族 F7。

## 2026-06-13 — fix(learning): 老師底部「評量」紅點與評量頁未填數一致（#788）

老師版底部導覽的紅點數字，現在會把本週（週一到今天）已上課但還沒填的評量一併算進去，跟評量頁顯示的「未填」數量一致，不會再出現頁面寫 2、紅點只寫 1 的情況。

開發備註：`learning-pending-summary` 新增 `week_attended_sessions_without_record` 並計入 total；PR #792；GitHub #788；in-app #157。

## 2026-06-13 — fix(director): 主任儀表板「系統內完成率」不再超過 100%（#786）

完成率改為每堂課最多計一次：之前同一堂課若有多筆評量紀錄會被重複計算，導致比率超過 100%，現已修正並以 100% 為上限。

開發備註：`AdoptionInsightsController` 分子改以最新非空 Progress 的出席 ClassSession 計數並 cap 100；PR #791；GitHub #786；in-app #156。

## 2026-06-07 — feat(calendar): SmartCalendar composables 剝離完成（#740 Step 7）

- `useCalendarDataLoad` / `useCalendarLeaveExtra` / `useCalendarSubstitute` / `useCalendarReschedule`
- `SmartCalendar.vue` **5260 → 3308** 行；拖曳調課 handler 仍留父層
- P4-b：`GET /api/v1/student-classes` 支援 `start`/`end` 視窗過濾 + 前端傳參
- 測試：`npm run test:calendar` 全綠（含 4 組 composable vitest）

開發備註：PR #773/#777/#778/#782/#787/#789；行數 <3000 留作 Step 7c（course-edit composable）後續。

## 2026-06-07 — feat(audit): schedule_audit_logs + ClassSessionObserver (#766)
- Added `schedule_audit_logs` 資料表，記錄課堂建立／更新／刪除的完整 old/new JSON 快照及操作人員
- Added `ClassSessionObserver`，自動在每次 `ClassSession` CRUD 時寫入稽核日誌
- Added `GET /api/v1/schedule-audit` API，支援分校／日期範圍／課堂 ID 篩選（分頁）

## 2026-06-07 — perf(calendar): loadCourses 平行化 student-classes ∥ schedules（#740 P4-a）

班級行事曆冷載時，課程清單與排程例外改為同時抓取，縮短等待時間；顯示結果與合併邏輯不變。

開發備註：新增 `calendarCourseLoad.js`（`fetchCalendarCoursesAndSchedulesParallel`）；`class-sessions` 仍串行（依賴 course ids）。理論節省 ≈ schedules 端點延遲（實測見 TD-062）。`test:calendar` +9 cases。Refs #740。

## 2026-06-07 — refactor(calendar): SmartCalendar Modals 群拆分（#740 Step 6）

班級行事曆五個 inline modal 剝離為獨立 presentational 元件，單堂檢視 modal 移除死碼分支，行數再降 661 行。

開發備註：`CalendarSessionEditModal` / `CalendarLeaveModal` / `CalendarRescheduleModal` / `CalendarSubstituteLegacyModal` / `CalendarExtraLessonModal` + `calendarModalRwd.css`。父層保留 form state 與 submit API。`SmartCalendar.vue` 4845→4184。`test:unit` 56 passed。技術文件 → `GUIDE_SMARTCALENDAR_REFACTOR.md` §4.6。Refs #740。

## 2026-06-07 — refactor(calendar): SmartCalendar 受控拆分暫時收尾（#740 Phase 4c）

班級行事曆大檔案完成第一階段受控拆分：純工具與五個 UI 葉子元件剝離，課程卡 CSS 祖先耦合改為 prop 驅動，視覺驗收通過；Modals 與效能平行化延後。

開發備註：`SmartCalendar.vue` 5260→4845 行（−415）。剝離 `lib/calendarDateUtils|calendarFormat|teacherColor` + `components/calendar/{TeacherColumnHeader,DayTabsBar,WeekTeacherChips,WeekNavBar,CourseBlockContent}`；`CourseBlockContent` 3 props（course/badges/layout）解耦 `:has()`/compact/容量徽章。PR #751–#757 全綠部署。技術文件 → `docs/GUIDE_SMARTCALENDAR_REFACTOR.md`。Modals、P4-a/b 仍 open，#740 暫不收案。

## 2026-06-07 — ops(rollback): 回滾就緒度檢查 + Rollback Runbook（#733）

新增「回滾就緒度」自動檢查與標準作業程序文件，確保萬一某次更新出問題時，系統能用最短時間、最小破壞地恢復到前一個正常版本。

開發備註：新增 `scripts/rollback-readiness.sh`（4 項非破壞性檢查：deploy.yml 自動回滾區塊完整、全 migration 有 down()、最新 commit 可乾淨 git revert、DB 備份還原 workflow 存在）+ `rollback-readiness.yml`（月排程 / 手動 / 改 deploy.yml 或 migration 的 PR 觸發）+ `docs/RUNBOOK_ROLLBACK.md`（含自動/手動回滾 SOP、DB 回滾、MTTR 量測）。零 production 風險。Refs #733。

## 2026-06-07 — test(frontend): 導入 Vitest 元件測試基礎建設（#729）

新增前端元件自動化測試護欄，未來改動共用 UI 元件若破壞行為，CI 會在合併前擋下，降低介面回歸風險。

開發備註：導入 `vitest` + `@vue/test-utils` + `jsdom`。新增 `vitest.config.js`（範圍限 `components/**/__tests__`，與 `src/lib/*.test.js` 純函式測試分離）、4 個 design-system 元件測試（AtButton/AtCard/AtEmpty/AtMetric，共 18 cases）、`npm run test:unit` script，並以 blocking step 納入 `ci.yml` 的 `vite-build` job。Closes #729。

## 2026-06-06 — fix(learning): 學習評量表日期排序修正（in-app #155）

學習評量表不再把「已核准但內容空白」的舊評量頂到最上面；需要填寫的優先顯示，已核准的依上課日期由新到舊排列，日期不再看起來亂。

開發備註：根因為 `LearningRecordsPage.vue` `sortRecords` 的 `missingBodyTier` 把 approved-empty 設 tier 0 置頂。抽出純函式 `lib/learningRecordSort.js`（approved/rejected/其他→tier1 依日期；僅 pending/changes_requested 未填→tier0）+ 單元測試 `learningRecordSort.test.js`（含 bug 端到端情境）；`sortRecords` 改呼叫 lib。篩選（「只看未填」toggle／分頁）不受影響。Closes #742。

## 2026-06-06 — feat(ui): 老師工作台 token 對齊 + dark mode 整併（#699 step 1）

開發備註：#699 Wave 1 補完三頁第一步（TeacherHomePage.vue）。raw hex 48 → 9，降 81.25%（AC ≥80%）。批次處理：(1) 移除 `var(--primary, #1976d2)` / `var(--ds-primary, #EF6C00)` / `var(--ds-primary-deep, #E65100)` / `var(--ds-primary-wash, #fff8e1)` fallback hex（13 處）— 全域已定義；(2) `#475569`/`#0f172a`/`#64748b`/`#334155` slate-tone → `--ds-ink-{secondary,,mute,secondary}`；(3) `#f8fafc` feedback-metric 底色 → `--ds-canvas-soft`；(4) `color: #fff` on primary/accent bg → `--ds-on-primary`（5 處：badge、day-tag、branch-chip、fill-btn hover、chat-btn）；(5) clockin-card hover / icon-empty `var(--bg-hover, #f5f5f5)` / `var(--bg, #f5f5f5)` / `var(--card-bg, #fff)` legacy fallback → DS token；(6) `.th-ckin-late` `#c62828` → `--ds-danger`；(7) `.th-icon-late`/`.th-badge-late` `#fce8e6`/`#c62828` → `--ds-danger-wash`/`--ds-danger`，並**移除 4 條 dark mode override（`#3b0c0c`/`#ef9a9a`/`#424242`/`#bdbdbd`/`#3b2612`/`#ffb74d` 系列）**——ds token 已自適應；(8) `.th-report-btn` red `#fef2f2`/`#ef4444`/`#fee2e2` → `--ds-danger-*`（active hover 改 filter brightness）；(9) `.th-form-substituted` `#e0e0e0`/`#757575` → `--ds-canvas-soft`/`--ds-ink-mute`。**保留 raw**：`.th-action-learning` 藍（`#e3f2fd`/`#1565c0`，多態語意色）、`.th-form-leave`/`.th-event-leave` 暖橘（`#fff7ed`/`#c2410c`/`#f97316`，請假狀態需與 warning 區別）、`color-mix(... #ffffff)` tint blend（4 處，tint 基色語法需求）。`npm run build` 通過。DirectorDashboard 與 LearningRecords 屬後續 step。

## 2026-06-06 — chore(docs): 文件治理向大公司看齊（INDEX 去重 / 過時修正 / CHANGELOG 滾動歸檔 / size gate）

文件庫整理：去重與修正過時描述讓 AI 更快找對資料、CHANGELOG 滾動歸檔省 token、補文件保鮮 metadata。

開發備註：分兩個 PR、於隔離 git worktree 進行（避免與並行 #692 working-tree race）。PR-A：presubmit CHECK 2 對 `chore/docs-*` 排除 CHANGELOG/archive 搬移於 size 計算；INDEX 合併重複命名 prefix 段 + 補 `ADR_`、設計摘要 navy+indigo→navy+品牌橘黃；`RULE_DESIGN_SYSTEM` 標題去 Stripe-Inspired + Badge/Forbidden indigo→info/品牌橘黃；`RULE_DESIGN_SYSTEM`/`PRICING_CONTRACT`/`ROLE_PLAYBOOK` 補 front matter 並納入 docs-integrity STALE_CHECK；APPROVED_PREFIXES += `ADR_`。PR-B（本次）：CHANGELOG 滾動歸檔——主檔只留當月，2026-05（162 條）移入新 `archive/CHANGELOG_ARCHIVE_2026-05.md`、2026-04（114 條）append 進既有 04 archive（零丟失，補回 archive 缺的 04-25~04-30），主檔頂部加 archive 導航。對齊 Keep a Changelog。

## 2026-06-06 — feat(ui): 學生管理表單 / 包套 / 歷史 / LINE / Toast token 對齊（#692 wave C）

開發備註：#692 StudentsList Wave 2-2 第三階段（表單 + package + history + LINE + toast + dark mode 整併）。**完成 #692 AC：raw hex 143 → 28，降 80.4%**。`.form-section-title`/`.rfid-bind-row input`/`.required` legacy var + `#ddd`/`#f5f5f5`/`#333` → `--ds-primary`/`--ds-hairline`/`--ds-hairline-input`/`--ds-canvas-soft`/`--ds-ink`/`--ds-danger`。`.cost-preview` 漸層 `#FFF8E1→#FFECB3` + border `#FFE082` → 實色 `--ds-primary-wash` + `--ds-hairline-input`；`-label` `#5D4037`、`-value` `var(--primary)`、`-formula` → `--ds-ink-secondary`/`--ds-primary`/`--ds-ink-mute` 並補 `tabular-nums`。`.tag-paused-sm`/`.tag-expiring` 全部 hex → `--ds-warning-{wash,}`；`.btn-renew-warn` `#ff9800`/`#fff`/`#e65100` → `--ds-warning`+`--ds-on-primary`，hover 用 `filter: brightness(0.92)` 取代第二個 hex。**保留 `.tag-package` 紫色（套餐多態語意色，無 ds token）**。`.sl-empty-active`/`.sl-history-*` 共 25 個 slate-tone hex → `--ds-ink-{mute,secondary}`/`--ds-hairline{,-input}`/`--ds-canvas-soft`/`--ds-shadow-1`；`.sl-tag-history--settled` 綠 → `--ds-success-*`；**保留 `--completed` 藍（無 ds token）**。`.line-bound-badge`/`.line-binding-id` 維持 **LINE 官方 `#06C755`**（third-party brand 不可換 token）；周邊 layout `#f5f5f5`/`#9e9e9e`/`#757575`/`#ef5350`/`#fff` → `--ds-canvas-soft`/`--ds-ink-mute`/`--ds-danger`/`--ds-on-primary`。`.toast-notification` `#323232`/`#fff` + 硬編陰影 → `--ds-ink`/`--ds-on-primary`/`--ds-shadow-2`。**Dark mode 區大幅整併**：12 條 `[data-theme="dark"]` override 拿掉 11 條（ds token 已自適應 dark），僅保留 `.sl-tag-history--completed`（藍多態無 token）。Template inline color：rfid-unbound icon `#bdbdbd`、invoice modal subtitle/loading/empty/due-date hint `#666`/`#aaa`/`#888`、sessions-near-empty 與 package-hint `#e65100`/`#7a4b00`、duplicate-course-heading `#e65100` 全部抽出為 scoped class（`--ds-ink-mute`/`--ds-warning`）。移除 §7 禁止的 emoji 狀態圖示：「💰 加購堂數」「🎓 年級升級」「⚠️ 此學生已有進行中的課程」 → 純文字。`npm run build` 通過。

## 2026-06-06 — feat(ui): 學生管理列表 / 狀態 chip / 課程展開區 token 對齊（#692 wave B）

開發備註：#692 StudentsList Wave 2-2 第二階段（列表 + 狀態 + 課程展開）。`.student-row` hover/expanded `#FFF8E1`/`#FFF3E0` → `--ds-primary-wash`；border-bottom `var(--accent)` → `var(--ds-primary)`；`.student-select-checkbox` accent → `--ds-primary`。狀態左邊框：active `#43a047` → `--ds-success`、paused `#e65100` → `--ds-warning`；**graduated `#1565c0` 藍、transferred `#7b1fa2` 紫無對應 ds semantic token，維持 raw 待 token 擴充**（同 #691 wave C 原則）。`.student-avatar-mini`：base 漸層 `#43a047→#66bb6a` 改實色 `--ds-success`、paused 漸層改 `--ds-warning`、graduated/transferred 漸層 → 實色 raw；`color: #fff` → `--ds-on-primary`。`.subject-pill` `#E8F5E9`/`#2E7D32` → `--ds-success-wash`/`--ds-success`；`.low` `#FFEBEE`/`#C62828` → `--ds-danger-wash`/`--ds-danger`。`.note-icon` `#ffab00` → `--ds-warning`。`.student-status-badge.paused` → `--ds-warning-*`（graduated/transferred 同上保留）。`.rfid-tag` `var(--primary)` → `var(--ds-primary)`；`.rfid-unbound` `#bdbdbd` → `--ds-ink-mute`。`.mini-progress` `#e8e8e8` → `--ds-hairline`。`.day-chip` 5 個 hex → `--ds-hairline`/`--ds-canvas-soft`/`--ds-ink-secondary`/hover `--ds-primary`+`--ds-primary-wash`/selected `--ds-primary-deep`+`--ds-primary`+`--ds-on-primary`。`.course-detail-row` `#FAFAFA` → `--ds-canvas-soft`；`.course-panel` border `var(--accent)` → `--ds-primary`；`.course-panel-header h4` `var(--primary)` → `--ds-primary`。`.student-note-line`/`.course-memo-line` `#64748b` → `--ds-ink-mute`。`.course-inner-table` `#F0F0F0`/`#EEEEEE` → `--ds-canvas-soft`/`--ds-hairline`。`.status-tag.one_on_one` → `--ds-primary-wash`+`--ds-primary-deep`、`.tutoring` → `--ds-success-*`（1on2/1on3/trial 多態語意色保留 raw）。raw hex 129 → 98。表單 / package tag / history / LINE / toast 屬 wave C。`npm run build` 通過。

## 2026-06-06 — feat(ui): 學生管理頁首+篩選列+批次工具列 token 對齊（#692 wave A）

開發備註：#692 StudentsList Wave 2-2 第一階段（header + filter + bulk + 共用 chip）。`.close-btn`/`.paid-date-hint`/`.invoice-status-chip.{paid,unpaid,partial}`/`.invoice-skeleton` 原 raw hex 改 `--ds-{success,warning,primary}-wash` + 對應 ink；`.header-icon` `var(--primary)` → `var(--ds-primary)`；`.stat-badge` `#FFF3E0`/`#E65100` → `--ds-primary-wash`/`--ds-primary-deep` 並補 `tabular-nums`；`.stat-badge-light` `#f5f5f5`/`#78909c` → `--ds-canvas-soft`/`--ds-ink-mute`；`.button-outline` legacy var → `--ds-canvas`/`--ds-hairline` 並對齊 secondary 按鈕語意；`.bulk-toolbar` `#E3F2FD`/`#90CAF9`（藍 info）→ `--ds-primary-wash`/`--ds-hairline-input`（品牌橘 wash）；`.filter-bar`/`.search-icon` legacy + `#bdbdbd` → `--ds-hairline`/`--ds-ink-mute`。Body/列表狀態色/RFID/課程展開區屬 wave B，modal/表單/package/history/LINE 屬 wave C。raw hex 143 → 129。`npm run build` 通過。

## 2026-06-06 — refactor(identity): runtime 移除 Teacher table 依賴，改以 User/UserCampus 為老師權威來源

開發備註：Phase 2。老師資料 runtime 改以 `User`（姓名、電話、LineID）與 `UserCampus`（分校、RFID）為權威來源；`Teacher.RFID` 已由 `UserCampus.RFID` 完全取代。更新老師建帳/更新/刪除、RFID 刷卡、老師打卡、LINE 通知、課程/評量/財務/出勤查詢與合併工具，不再 join/write `Teacher` table。`TeacherSingIn.TeacherID`、`StudentClass.TeacherID`、`StudentSingIn.TeacherID`、`schedules.teacher_id` 語意維持 `User.id`。新增 migration 將 legacy `Teacher` 的 phone/LineID/CampusID/RFID 補回 `User`/`UserCampus`，`down()` 不刪 live data。測試 fixture 同步移除 `Teacher` table 假設；本機 PHP 不可用且依使用者指示改由 GitHub Actions 執行測試。

## 2026-06-06 — feat(ui): 課程 modal 中性結構色 token 化（#691 第三階段）

## 2026-06-06 — feat(ui): App 外殼去裝飾、品牌色統一（#698 topbar/FAB/banner）

全站共用外殼的視覺收斂：頭像、說明浮動鈕、系統更新提示列從多色漸層統一為單一品牌色，與設計系統一致。

開發備註：#698 App shell chrome 去裝飾。`App.vue` `<style>`：(1) `.update-banner` 藍漸層（`#0ea5e9→#2563eb`）→ `--ds-primary` 實底 + `--ds-shadow-1`；按鈕改 `--ds-canvas`/`--ds-primary-deep`/hover `--ds-primary-wash`。(2) `.account-avatar` 橘漸層（`#f97316→#fb923c`）→ `--ds-primary` 實色。(3) `.global-guide-btn`（說明 FAB）橘漸層（`#ff9800→#ff6f00`）→ `--ds-primary` + `--ds-shadow-2`。(4) `.account-role`/`.account-menu-chevron` → `--ds-ink-mute`；`.account-menu-btn-danger` → `--ds-danger`/`--ds-danger-wash`。登入頁品牌 hero radial 光暈屬品牌動畫，依設計系統保留。`npm run build` 通過。



課程相關彈窗（堂次編輯、續約月結）的容器底色、標題、輸入框邊框等中性樣式統一對齊設計系統；出缺勤狀態色、計費比較色等「功能語意色」維持不變（屬設計 token 擴充議題，另議）。

開發備註：#691 reference page 治理第三階段（modal 群中性結構）。`SessionEditModal.vue`：`.session-edit-info` 底色、`.se-label`/`.se-section-title`/`.se-sub-hint`/`.se-loading`/`.field-note`/`.se-charge-label`/`.se-charge-hint` 文字色、動作按鈕與 `.se-time-input` 邊框 → `--ds-*`。`RenewMonthlyModal.vue`：`.period-hint`、`.info-row` → token。**保留**：`.se-st-*`（出缺勤狀態）、`.se-btn-*`（動作色）、`.se-charge-standard/higher/lower`（計費比較）等功能語意色——現有 ds semantic token（success/warning/danger/info）不足以表達 scheduled 藍/reschedule 紫等多態區分，貿然替換會降低可辨識度，登記為後續 design token 擴充。`npm run build` 通過。



課程管理頁的統計列、課程列表卡片、表格從多層漸層光暈與彩虹裝飾條收斂為乾淨的白底卡片與中性表格，狀態標記（暫停、聚焦）改用統一的語意色，整體視覺一致、好掃讀。

開發備註：#691 reference page 治理第二階段（內容容器；狀態 chip 細節與 modal 留後續 PR）。`CourseManagement.vue` `<style>`：(1) `.stats-strip`/`.stats-orb` 移除漸層底與 `::after` 彩線（`#0f172a→#f59e0b`）、`.stats-orb-total` radial 改 `--ds-primary` 底邊；數字字重 950→700。(2) `.table-card`/`.student-group-card` 移除多層 gradient 背景、彩虹 `::before` 頂條（`#38bdf8`/`#f59e0b`）、hover transform/大陰影 → `--ds-canvas` + `--ds-shadow-1`，圓角 22→12。(3) skeleton 彩虹 shimmer → 中性 `--ds-canvas-soft`/`--ds-hairline`。(4) `.creation-success-banner`/`.focus-mode-banner`/`.student-group-paused-badge` 改 success/info/warning token wash。(5) `.expand-indicator`/`.student-group-meta`/`.focus-btn`/`.student-group-add-row` 色票 → `--ds-*`。(6) `.course-table` thead/th/td 與 `.course-row` 左側 accent bar（`rgba(14,165,233)`→`--ds-primary`）token 化。頁面 hex 347→311。`npm run build` 通過。



課程管理頁的頁首從浮誇的漸層光暈 hero（多層放射/旋轉光暈、超粗大標題）收斂為乾淨的白底卡片，標題字級字重回到後台應有的沉穩感；篩選列、主要按鈕統一品牌色，整體更專業、更好掃讀。

開發備註：#691 reference page 治理第一階段（頁首 + 篩選列，內容區與 modal 留後續 PR）。`CourseManagement.vue` `<style>`：(1) 移除 `.course-page::before` 背景 gradient mesh 光暈、`.course-header-card::before`（grid mask）與 `::after`（conic 旋轉光暈）三組裝飾偽元素。(2) `.course-header-card` 改 `var(--ds-canvas)` + `--ds-hairline` + `--ds-shadow-1`，圓角 24→16。(3) `.page-title` font-weight 950→700、clamp 3.6rem→2rem；`.command-kicker` `#7dd3fc`→`--ds-ink-mute`、字重 900→700。(4) `.meta-pill`/`.btn-soft`/`.filter-bar`/`.filter-field` 色票全改 `--ds-*`，移除 inset 高光與 hover transform/大陰影。(5) `.btn-accent` 主 CTA 由深色 gradient → 實心 `--ds-primary`，hover `--ds-primary-deep`。`npm run build` 通過。



左側選單目前選中項目改為更沉穩的「左側色條 + 品牌色淡底」（參考大型後台軟體做法），取代原本較搶眼的漸層光暈；待辦數字標記顏色統一為品牌色與警示紅，整體更專業一致。

開發備註：#698 App 外殼治理第一階段（側欄）。`styles.css`：(1) 新增 `--sidebar-active-wash`/`--sidebar-active-bar`/`--sidebar-badge-bg` token（light + dark 各一組）。(2) `.sidebar-nav button.active` 移除舊 indigo gradient + indigo 外陰影（殘留 `rgba(83,58,253,*)`），改 `inset 3px` 左色條 + 半透明品牌色淡底。(3) `.nav-badge` 硬編碼 `#ff7043` → `var(--sidebar-badge-bg)`；urgent `#d32f2f` → `var(--ds-danger)`。`App.vue` loading 文案 `載入中...` → `載入中…`（`GUIDE_UI_COPY`）。`npm run build` 通過。topbar / 導覽 FAB / update-banner 留後續 PR。



啟動 UI 去 AI 化的元件化基礎建設：建立 4 個只吃設計 token 的共用元件，後續各頁面逐步替換，讓全站按鈕、卡片、空狀態、數字卡視覺一致。

開發備註：新增 `frontend/src/components/design-system/`（AtButton：primary/secondary/ghost/danger × sm/md，primary 改實心非 gradient；AtCard：default/inset + header/actions slot；AtEmpty：Material icon + 標題 + 下一步說明，禁 emoji；AtMetric：`tabular-nums` 數字 + delta tone + accent 邊條）+ README（用法 + 禁止清單）。全部僅消費 `--ds-*` token，零硬編碼色。示範：`LearningRecordsPage` 上一堂摘要空狀態改用 `AtEmpty`、loading 文案改全形省略號（對齊 `GUIDE_UI_COPY.md`）。`npm run build` 通過。Epic #687 Sprint 0 基礎建設。



開發備註：批次完成 Epic #687 文件/基礎建設層：(1) 新增 `docs/GUIDE_UI_COPY.md` — 空狀態公式、loading/error 規範、placeholder/按鈕文字規則（Closes #690）。(2) 新增 `docs/GUIDE_DESIGN_QA_SMOKE.md` — 逐角色 smoke 路徑 + 上線後 OPS 確認（Closes #705）。(3) 新增 `scripts/design-hex-count.sh` + `docs/design-hex-baseline-2026-06-06.json`（grand total 3800 hex，作為 #687 KPI baseline）+ `npm run metrics:design-hex`（Closes #706）。(4) `.github/pull_request_template.md` 新增 Design System 檢核區塊（Closes #697）。(5) `docs/RULE_DESIGN_SYSTEM.md` §9 新增 Rollout Tracker 表格連結所有子 issue（Closes #709）。(6) `docs/INDEX.md` 前端開發章節補 UI_COPY_GUIDE / DESIGN_QA_SMOKE 導航。(7) README：頁面數 30→33、近期重點更新改 2026-06、補 ReleaseNotesPage / BranchManagementPage。


## 2026-06-06 — feat(learning/ui): 評量新增「上一堂摘要」+ 首批四頁視覺治理（#154）

老師/主任在學習評量表可直接看到「上一堂上到哪裡」（含代課老師那堂），不用再翻歷史；同時完成首批四個高曝光頁面的視覺一致化，降低介面割裂感與 AI 模板感。

開發備註：`GET /api/v1/learning-records/latest-approved-summary` 回傳補齊 `is_substitute`、`homework_status`、`quiz_score`、`next_week_test_scope`；`LearningRecordsPage` 新增上一堂摘要卡（載入/錯誤/空態、代課標示），並在編輯既有/課表開單/主任手動開單時自動載入。新增 regression：`SubstituteTeacherTest::test_latest_approved_summary_uses_effective_substitute_teacher`。UI 治理首批覆蓋 `DirectorDashboard`、`TeacherHomePage`、`LearningRecordsPage`、`SmartCalendar`：工具列與容量標示 token 化、移除高辨識 emoji 呈現、CTA 與重點色對齊 `RULE_DESIGN_SYSTEM.md` token。

## 2026-06-06 — security(repo): 移除另外 2 個 production PII SQL dump + .gitignore 防再犯

開發備註：承上 docs 大掃除，repo 內再揪出 2 個含 PII 的 dump——`AllTrue (3).sql`（root，1920 行）、`backend/storage/backups/prd-e-20260418-232201.sql`（production 備份，6156 行），含真實 `Student`/`StudentClass`/`Teacher` 資料。皆 `git rm` 出 HEAD。新增 `.gitignore`：`*.sql`（`!scripts/*.sql` 保留查詢腳本）+ `backend/storage/backups/`。歷史清除（filter-repo + force-push main）屬 P0，依風險取捨**暫不執行**，決策留檔於 `docs/SECURITY.md §6`（private repo + 單一 committer，殘留風險可接受；repo 轉 public/新增協作者前再重評）。

## 2026-06-06 — chore(docs): docs/ 大掃除（移除 PII 備份、去重、歸檔、補導航）

開發備註：(1) ⚠️ **移除 `docs/AllTrue_backup.sql`**——2026-02-07 的 phpMyAdmin dump，含真實 `Student`/`StudentClass`/`StudentSingIn`/`Teacher` INSERT（姓名/RFID/LineID），不該入 repo（個資法）。已 `git rm` 出當前樹；**git 歷史殘留需另外決策**（filter-repo 需 force-push，屬 P0，待使用者批准）。(2) 刪除 `docs/` root 與 `archive/` 重複的 `使用說明_主任與超級管理員.md`、`更新網站前端.md`（body 相同，只差封存 banner；保留 archive 版）。(3) `PORSCHE_VISUAL_SYSTEM.md`（已 superseded）移入 `archive/`。(4) 孤兒檔補進 INDEX 導航：`api-swipe-rfid.md`、`SUPER_ADMIN_AND_MIGRATIONS.md`、`AMBIENT_AUDIO_LICENSES.md`、`SMOKE_TEST_RUNBOOK.md`、`ADOPTION_QUALITY_METRICS.md`、`reviews/PRODUCT_GAP_REVIEW_2026-06.md`。(5) 修正 README 3 處指向 root 但實際在 archive 的過時路徑。(6) `git update-index --chmod=-x` 清掉 4 個誤設可執行權限的文件。docs-integrity-check `--strict` 全綠。

## 2026-06-06 — chore(deps/test): phpstan 2.2.2 + guzzle 7.11；修 factory faker 姓名超長 CI flaky

開發備註：清掉殘留的 Dependabot PR 與分支。(1) phpstan/phpstan 2.2.1→2.2.2 + guzzle 7.10.5→7.11.0（promises/psr7 同組），phpstan patch 在 `CoursePackageController::createMultiSubject` 報 13 個 `ternary.alwaysTrue`/`nullCoalesce.offset` 等——皆 larastan 由 `payment_type` 驗證規則推 `$isMonthly` 為常數真的誤報（runtime 仍可為 `session`，改 code 會弄壞 count 制方案），故併入 `phpstan-baseline.neon`、不動計費邏輯（取代 dependabot #678 → #679）。(2) `StudentFactory.name`/`UserFactory.Name`/`CampusFactory.name` 原直接用 `faker->name()`/`city()` 寫入 VARCHAR(32) 欄位，遇較長姓名（如 33 字 "Prof. … Jr."）間歇性 `1406 Data too long` 失敗 → 一律 `mb_substr(…, 0, 32)`（鏡像同檔 SchoolName 既有寫法），消除隨機 CI flaky。

## 2026-06-01 — chore(notify): 學習回饋／回覆接推播基礎建設（dark launch，預設關閉）

開發備註（dark launch，功能未對外開啟，故不進版本公告卡）：家長在學習評量留言或追加回覆時通知老師／主任；老師回覆家長時推播家長 LINE（需綁定）。家長可於家長系統關閉。

開發備註：T3（家長 PII + LINE 推播 + 防騷擾）。新增 `FeedbackPushNotifier` 服務串接 `LearningRecordFeedbackController` 三個事件（`parentUpsert`/`parentReply` → 站內 `Notification`（Type `lr_feedback`，SourceKey 去重）；`staffReply` → 家長 LINE，鏡像 `SendTuitionReminders` 的 `StudentLineBinding`+`Campus.messaging_channel_token` 推播）。**dark launch**：perfflag `feedback_push_enabled` 預設 **false** → 全程 no-op，production 行為不變；確認推播節奏/文案後再以 `PERF_FEEDBACK_PUSH=true` 開啟。防騷擾：同 (feedback,direction) 於 `feedback_push_merge_window_seconds`（預設 600=10 分鐘）內合併一則。個資退出權：`student_line_bindings.notify_learning_feedback`（預設開）+ `GET/PUT parent/notification-preferences`。Best-effort：推播失敗只記 log、不阻斷主流程。涵蓋測試：flag-off no-op、staff 站內、parent LINE、merge window、opt-out、跨校隔離、推播失敗不丟出。**未做（flip flag 前的 fast-follow）**：ParentPortal 退訂 toggle UI；關聯 TD-013（LINE 綁定率低 → 觸達上限）、TD-057（reply-rate KPI）。PRD：`.cursor/plans/feedback-push-notifications_2026-06-01.md`。

## 2026-06-01 — feat(billing): 建課即時費用試算與計價方式提示

建立課程時，排課摘要會即時顯示「每堂計費／每小時計費」與預估總額，幫助主任確認金額正確，降低單價填錯造成的費用落差。

開發備註：`UniversalClassScheduler` 摘要卡新增費用試算面板，鏡像後端 `EnrollmentService::store` 計價契約（session：round(單價×堂數)；hour：round(單價×總時數)，總時數=堂數×平均每堂分鐘/60）。計價方式（每堂／每小時）與送出 payload 同源（皆由 `hasPerDayDuration` 推導），故預覽顯示的單位必與實際入帳一致，直接防止 Bug #129 類的單位混淆 ×2 錯帳。公式抽成純函式 `estimateCreateCharge`（`coursePricing.js`）+ 單元測試（含 8,800 vs 17,600 對照、四捨五入、防呆），已 wire 進前端 `build` chain（CI 把關）。混合時長之 hour 模式為「平均」估算（uniform 為精確），面板標示「預估」。`CourseEditForm` 編輯態（含 preservedDelta）暫未加，留待後續。

## 2026-06-01 — chore(perf): /class-sessions 代課解析改 derived-table join（TD-058 / TD-062 Phase 3）

開發備註：`ClassSessionController::index` 解析代課老師原以 per-row correlated subquery `sub_sched.id = (SELECT MAX(sub2.id) …)`，且 `DATE()`/`SUBSTRING()` 包裹欄位使索引失效（TD-058，主查詢 1–3.5s 主因）。改為預先彙總的 derived-table join（鏡像既有 `lr`/`si` 的 `MAX(id)` 衍生表）：inner aggregate 取每 `(student_course_id, schedule_date, HH:MM)` 的 `MAX(id)`，並在彙總內過濾 `teacher_id <> 課程老師`、`status='scheduled'`、`original_schedule_id IS NOT NULL`，與原 subquery 等價。`schedule_date` 為 DATE、`start_time` 為字串，故 GROUP BY 該兩鍵等同原 DATE()/SUBSTRING() 正規化，不多出列。golden 保護：18 條代課/調課/可見性/HH:MM:SS 格式測試 + ClassSessionApi/SameDayMultiSlot/Batch/Duplicate/TimeSync/ReschedulePrecision 全綠（byte-identical）。`teacherTrust` 同款 subquery 未改，留待後續。

## 2026-06-01 — chore(perf): /class-sessions 日期視窗改索引友善（TD-062 Phase 2）

開發備註：`ClassSessionController::index` 的 `start`/`end` 過濾由 `whereDate('cs.SessionDate',…)` 改為裸欄位比較 `where('cs.SessionDate',…)`。`SessionDate` 為 DATE 欄位，故結果 byte-identical，但不再以 `DATE()` 包裹欄位 → range 可命中 `(StudentClassID, SessionDate)` 複合索引。characterization 測試 `ClassSessionDateWindowFilterTest` 鎖定閉區間 [start,end] 行為；250 條 class-session/代課/調課/點名相關測試全綠。

## 2026-06-01 — chore(perf): 行事曆換週/換日視窗快取（TD-062 Phase 1）

開發備註：`SmartCalendar` 換週/換日原本每次都全量重抓 3 支 API（student-classes/schedules/class-sessions）。新增「視窗快取」：記錄上次抓取的 `{分校, ±21 天範圍}`，換週/換日若目標週仍落在此視窗內（同分校）即跳過網路、由既有 reactive computed 直接重渲染 → 命中時 0 net request。`loadCourses()` 與 occurrence 合併完全未動；所有 mutation（建課/請假/調課/點名…）仍走完整重抓，故無 staleness 風險。判斷邏輯抽成純函式 `isRangeWithinFetchedBounds` 並加單元測試（`calendarLoadPerformance.test.js`）。

## 2026-06-01 — chore(deps): composer 鎖定 PHP 8.2 平台 + 月初帳務測試健全化

開發備註：(1) `backend/composer.json` 設 `config.platform.php=8.2.30`，避免 dependabot/`composer update` 解析出需 PHP 8.3/8.4 的相依（如 `symfony/css-selector` v8、`zipstream` 3.2.2）而在 8.2 runtime 裝不起來（dependabot PR #643 即此症）。順帶安全升版：`symfony/routing` v5.4.48→v5.4.53、`symfony/polyfill-intl-idn` v1.33.0→v1.38.1（清掉 2 筆 OSV 發現，TD-061）、`guzzle` 7.10.5、`maatwebsite/excel` 3.1.69，並把 `laravel/framework` 由 dev 分支 pin 至穩定 `v8.83.29`。(2) `CoursePackageMonthlyBillingTest` 月結堂數測試夾住堂次日期 ≤ 今天，修正每月 1 號（月內未來日期被 `alerts/tuition` 正確排除）造成的時間敏感失敗。

## 2026-08-01 fix: billing reconciliation and explicit batch approval

- 所有 billing read surfaces（帳務中心、帳單列表、繳費單、對帳查詢）依帳單月份的實際已上課堂計算，顯示原始金額與差異警示；不再默默沿用過期的預存金額。
- 批次核准只會處理前端明確勾選的評量；選取範圍與目前權限/狀態不一致時整批停止，不會擴大核准範圍。
- 新增事件檢討與回歸測試：`docs/incidents/2026-08-01-billing-and-batch-approval.md`。

## 2026-08-08 — feat(payroll): 正職老師薪資要件分項篩選

<!-- release-notes: staff_update=staff-2026-08-08-payroll-eligibility -->

- 新增主任用「正職老師薪資要件」報表，依週／月／年度分層，按每週 16 段、假日 16 小時、平日下午課、特殊表現、扣除案件與科目數獎金分開判定。
- 接上主任唯讀 API 與報表頁面，依分校範圍顯示符合、不符合及待人工確認結果。
- 新增薪資制度 115.07（2026-07-01）政策引擎；資料不足會列為「待人工確認」並回報缺少欄位，不會直接判定不符合。科目數與一對三獎金採附件 1–50 科目表，不推算缺漏值。
- 新增教師薪資事件、升學成果與扣除案件的 additive migration；扣除案件只有主任及總部審核完成才會自動計入，報表端維持唯讀。
- 新增主任 API、前端頁面與 8 個純單元測試；PHPUnit、PHPStan、Vite build、design token guard 與 diff check 通過。正式部署待 PR 審核合併後由既有 CI 流程執行。

## 2026-08-13 — fix(teacher-home): 穩定教師首頁課表與評量投影

<!-- release-notes: staff_update=staff-2026-08-13-teacher-home-projection-integrity -->

- 教師首頁現在會以統一的課堂資料欄位合併同一學生、日期與時段的重複投影，避免同一堂課顯示兩張評量卡。
- 週課表重新載入時保留上一份有效結果，並忽略過期請求，避免畫面閃爍或跨分校課程短暫消失。
- 此修正不更動上課、評量、點名或收費資料；若偵測到真正的資料重複，仍須走受控稽核與修復流程。

## 2026-08-13 — fix(calendar): 月例外堂次投影與關閉

<!-- release-notes: staff_update=staff-2026-08-13-monthly-projection-exception -->

- 修正月排課在合約時間與例外時間不同時仍能正確 materialize，並避免把補課或跨日改課目的列誤當成同一堂。
- 修正關閉已 materialize 的例外堂次時，不會連帶取消補課或跨日改課目的列。
## 2026-08-22 — fix(billing): 未收款課程堂數與費用更正（GitHub #1901）

<!-- release-notes: silent_ship=silent-2026-08-22-unpaid-billing-correction -->

- 新增主任／超級管理員專用的未收款堂數更正流程，可將錯誤購買堂數安全下修並同步正確總費用。
- 保留已上課與扣堂紀錄，取消超出堂數的未上課排程、重算餘額，並留下稽核事件；一般計費契約鎖定與已收款帳務流程不放寬。
## 2026-08-24 — feat(ops): 分校健康唯讀看板 V1

<!-- release-notes: staff_update=staff-2026-08-24-branch-health-v1 -->

- 超級管理員可在「分校健康」查看啟用分校的學生、教學、家長、教師與營運訊號，並點入查看來源、期間與下一步。
- V1 不做總分、排名、介入任務或自動通知；教師流失、capacity、完整續班率與家長客訴尚未接入時，會明確顯示「待接資料」。
- API 維持唯讀與 super_admin 分校範圍，未修改排課、出缺勤、扣堂、收費或既有主任首頁工作流。
## 2026-08-25 — fix(accounting): 回報先待對帳並保留入帳備註

<!-- release-notes: staff_update=staff-2026-08-25-payment-report-reconciliation -->

- 現金與匯款回報會先建立「待對帳」資料；主任確認入帳後才更新為已繳費、建立付款與電子收據，重複回報會直接導向待對帳分頁。
- 同一學生同科目有多筆課程時，帳務列顯示課程編號與日期，降低選錯課程而誤判未繳／已繳的機會。
- 回報備註會保留到對帳、電子收據與學生編輯頁的最近入帳備註，電子收據恢復提供複製入口；不會覆蓋學生長期備註。
## 2026-08-25 — fix(billing): 課程列表顯示待對帳

<!-- release-notes: staff_update=staff-2026-08-25-course-list-pending-report -->

- 課程管理與學生課程列表遇到尚未核帳的繳費回報時，會顯示「待對帳」，不再誤顯示「未繳費」或讓主任重複送出回報。
- 回報仍須由主任在帳務中心確認入帳；確認後才會變成「已繳費」並開立電子收據。
## 2026-08-25 — improved(billing): 電子收據可複製圖片

<!-- release-notes: staff_update=staff-2026-08-25-receipt-image-copy -->

- 電子收據新增「複製圖片」與「下載圖片」，可直接貼到 LINE 或下載後傳給家長；「複製文字」仍保留給需要文字內容的情境。
- 若瀏覽器不允許直接寫入圖片剪貼簿，畫面會提示改用「下載圖片」，不會讓主任誤以為已複製成功。

## 2026-08-25 — fix(billing): 課程列表顯示待對帳

<!-- release-notes: staff_update=staff-2026-08-25-course-list-pending-report -->

- 課程管理與學生課程列表遇到尚未核帳的繳費回報時，會顯示「待對帳」，不再誤顯示「未繳費」或讓主任重複送出回報。
- 回報仍須由主任在帳務中心確認入帳；確認後才會變成「已繳費」並開立電子收據。
# 2026-08-27 — chore(quality): 前端未使用程式碼新增 baseline ratchet

<!-- release-notes: silent_ship=silent-2026-08-27-eslint-unused-ratchet -->

- 以既有 ESLint 規則建立每檔 baseline，build 會阻擋新增的 `no-unused-vars` 問題，同時允許逐步清理歷史債。
- 這是工程品質防線，不改變主任、老師、家長的畫面或操作流程。
# 2026-08-27 — improved(ux): 主任可追蹤老師評量完成率

<!-- release-notes: staff_update=staff-2026-08-27-teacher-assessment-engagement -->

- 完整營運的「近期紀錄與分析」新增老師評量完成率，主任可切換近 7／14／30 天查看已填／應填與待跟進狀態。
- 樣本不足只顯示資料累積中，不公開競爭排名、不直接發 XP；既有分校權限、代課歸屬與出缺勤排除規則維持不變。
## 2026-08-28 — improved(ux): 側欄與今日工作頁面減少操作干擾

<!-- release-notes: staff_update=staff-2026-08-28-ops-ui-sweep -->

- 側欄保留所有功能入口，但將進階教學、報表薪資與訊息回報收進可展開區段，主任與老師先看到每日高頻工作。
- 出缺勤、教學工作台、老師管理與帳務中心統一頁首、摘要、篩選與載入狀態；原有點名、帳務、權限與資料流程不變。
## 2026-08-28 — fix(attendance,course): 請假安全撤銷與試聽轉正式

<!-- release-notes: staff_update=staff-2026-08-28-leave-trial-conversion -->

- 翟君和等請假堂次改回未上時，統一走「撤銷請假」；禁止先改成已上再改回未上，避免順延與評量／點名資料不同步。
- 舊資料缺少順延尾堂時，撤銷請假會只復原該堂並提醒做堂數對帳，不會刪除或任意改動其他堂次。
- 試聽轉正式改為單一操作：保留試聽堂與評量歷史、取消未來試聽排課、建立乾淨的正式堂數課程並記錄來源，避免轉移試聽堂造成超排。
- 課程列表的衝堂提示補上合約日期區間判斷；日期不重疊的續報不再被誤標衝堂。
## 2026-08-28 — fix: 出缺勤與學習評量表建立改為同一個一致性邊界

<!-- release-notes: staff_update=staff-2026-08-28-attendance-learning-record-integrity -->

- 已上／遲到狀態若無法建立或恢復有效學習評量表，出勤狀態更新會回滾，不再留下「有上課但沒有評量表」的半完成資料。
- 新增全系統一致性掃描與受控修復：已上／遲到缺評量會補回，未上／請假／取消的幽靈評量會作廢，並保留修復稽核與備份。
- 針對 2026-08-28 翟君和 13:00 社會堂次提供固定目標的受控資料修復流程，避免誤修其他堂次。

## 2026-08-28 — fix: 科目數統計摘要不再誤顯示 0

<!-- release-notes: staff_update=staff-2026-08-28-subject-units-summary -->

- 修正科目數統計頁讀取 API 字串型數值時的前端型別錯誤；摘要卡會與下方老師明細使用相同數據正常顯示。
- 補上非數字／空值的安全轉換與回歸測試，不影響科目數計算公式與權限。
## 2026-08-29 — improved(ux): 主任總覽檢視切換更清楚

<!-- release-notes: staff_update=staff-2026-08-29-director-view-switcher-a11y -->

- 主任總覽的「今天／完整營運」檢視切換補上明確的分頁與內容區關係，鍵盤與螢幕閱讀器能知道目前正在查看哪個工作區。
- 保留今日待辦優先、完整營運按需載入與原有導頁、資料、權限行為；本次不改任何營運規則。

## 2026-08-29 — improved(ux): 評量快捷篩選狀態更清楚

<!-- release-notes: staff_update=staff-2026-08-29-learning-filter-chips-a11y -->

- 評量頁的未填、需修改與家長留言快捷篩選改用明確的按鈕與選取狀態，鍵盤與螢幕閱讀器能辨識目前篩選。
- 保留原有篩選條件、排序、資料、權限與審核流程；本次不改 API 或評量內容。
## 2026-08-29 — improved(ux): 評量課表檢視控制更清楚

<!-- release-notes: staff_update=staff-2026-08-29-learning-schedule-a11y -->

- 評量頁課表的「今日／本週」切換、週次前後按鈕與填寫操作補上明確的按鈕語意與名稱。
- 保留原有課表資料、填寫導向、評量內容、權限與 API；本次不改排課或審核規則。
# 2026-08-29 — improved(ux): 評量檢視與操作按鈕語意更清楚

<!-- release-notes: staff_update=staff-2026-08-29-learning-view-actions-a11y -->

- 評量列表／卡片檢視與內容預覽會明確告知目前選取狀態，鍵盤與螢幕閱讀器能辨識正在查看的模式。
- 批次審核、單筆操作、篩選、匯出、草稿與彈窗關閉按鈕補上明確按鈕型別，避免在表單情境被誤當成送出。
- 保留既有評量資料、審核流程、權限、API 與操作行為；本次只補控制項語意。
# 2026-08-29 — improved(ux): 學習檢測操作按鈕語意更清楚

<!-- release-notes: staff_update=staff-2026-08-29-assessment-actions-a11y -->

- 學習檢測的建立、發布、結果、作答與補強操作補上明確按鈕型別，避免在表單情境被誤當成送出。
- 建立檢測與查看結果的彈窗補上 dialog 角色與標題關係，進入不同工作階段時提供清楚上下文。
- 保留原有檢測資料、結果登錄、主任複核、補強追蹤、權限與 API 行為；本次只補控制項語意。
# 2026-08-29 — improved(ux): Bug 回報補充定位資訊

<!-- release-notes: staff_update=staff-2026-08-29-bug-report-triage-context -->

- 回報問題時可選填發生時間與相關資料編號，讓後續查找特定學生、課程、課堂或發票更快。
- 描述欄提示「做了什麼、實際看到什麼、原本預期什麼」，並提醒不要填寫密碼；既有頁面、裝置與瀏覽器資訊仍會自動附帶。
- 欄位為選填，不改權限、資料內容、帳務或排課判斷。
- 處理人員查看回報詳情時會直接看到這些補充線索；一般回報者不會看到內部處理區塊。
- 送出成功會顯示回報編號，方便回到 Bug 回報頁查看後續進度。

# 2026-08-29 — improved(ux): 教師首頁提示音狀態更清楚

<!-- release-notes: staff_update=staff-2026-08-29-teacher-home-notification-a11y -->

- 教師首頁的待辦提示音開關會明確回報目前開啟／關閉狀態，今日靜音動作也有清楚名稱。
- 保留提示音偏好、今日靜音、待辦排序、點名導頁與既有資料流程；本次只改善控制項回饋。

## 2026-08-29 — improved(ux): 老師管理彈窗上下文更清楚

<!-- release-notes: staff_update=staff-2026-08-29-teachers-modal-semantics -->

- 老師管理的新增、編輯與批次新增彈窗補上 dialog 角色與標題關係，操作上下文更清楚。
- 老師管理彈窗的取消、儲存與批次結果操作按鈕補上明確型別，避免表單情境產生誤送出。
- 老師管理的搜尋、狀態與科目篩選補上欄位標籤關聯，定位資料更直覺。

## 2026-08-29 — improved(ux): Bug 回報列表支援鍵盤選取

<!-- release-notes: staff_update=staff-2026-08-29-bug-list-keyboard -->

- Bug 回報列表項目改為可用鍵盤聚焦與選取，按 Enter 或 Space 即可開啟詳情。
- 已選取的回報會向輔助工具清楚標示；滑鼠操作與分診流程不變。
