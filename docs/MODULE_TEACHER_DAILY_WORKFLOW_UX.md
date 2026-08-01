# Module PRD：老師每日工作流

> Bounded context：Teacher daily workflow · `TeacherHomePage.vue` + `AttendancePage.vue` · 2026-08-02
> Epic：[#1600](https://github.com/jerry200176-png/AllTrue_System/issues/1600)

## 1. 問題定義

老師首頁目前把「任務中心、待點名、待填評量、補填提醒、家長回饋、週進度、回饋分析、週課表、聊天與系統信任」同時呈現。這些資料各自有價值，但入口沒有單一排序真相，老師仍要自己判斷下一步；同一件工作也可能在任務中心、今日待辦、補填提醒與週課表重複出現。

點名頁則同時承擔今日點名、批次到班、課表出入回報、補建堂次、歷史補登等不同風險的操作。手機版雖有卡片，但狀態選擇、回報與確認按鈕仍在同一層，容易把「選狀態」誤認為「已完成」。

## 2. 研究與設計判準

### 企業產品

- Microsoft Teams Assignments 將工作按 `Upcoming / Ready to grade / Past due / Returned / Draft` 分組，清單按期限排序，進入一筆工作後再做詳細操作。這提供「狀態先於細節、下一步可直接進入」的模型。
- Google Classroom 教師 To-do 以 `Reviewed / To review` 組織工作，從列表直接進入 Student work；Grades 以行列和狀態篩選支援大量處理，且「儲存草稿」與「Return」是不同狀態。
- Primer 的 ActionList 採單欄、主文字＋描述＋右側行動；Button 規範每組只保留一個 primary action。這適合 AllTrue 的 mobile task queue。
- Power BI／Grafana／Metabase 的共同原則：先顯示回答當下問題的資訊，分析與歷史資料下鑽，不讓儀表板變成所有元件的集合。

### Star／開源 repo 實作觀察

- `GibbonEdu/core` 的 Markbook 與 Attendance 以角色／課程範圍先過濾，再以表格／狀態列呈現；細節在進入項目後展開，而不是全部塞在首頁。
- `chatwoot/chatwoot` 使用文字化狀態、數量與單欄工作列表，列表項目的右側動作不與內容競爭。
- `vbenjs/vue-vben-admin` 的 layout／page header 將標題、範圍、動作固定在頁首，資料區可替換，避免各頁自行發明 header。
- `filamentphp/filament` 的 page header → table heading → filters → row actions 層級，適合把「今天要做什麼」與「管理全部資料」分開。

研究來源：

- [Microsoft Teams educator assignments](https://support.microsoft.com/en-us/education/view-and-navigate-your-assignments-educator)
- [Microsoft Teams assignments and grades](https://support.microsoft.com/en-us/education/assignments/assignments-and-grades-in-your-class-team)
- [Google Classroom teacher To-do](https://support.google.com/edu/classroom/answer/9849192?hl=en)
- [Google Classroom grading and feedback](https://support.google.com/edu/classroom/answer/16643267?hl=en)
- [Primer ActionList](https://primer.style/product/components/action-list/)
- [Primer Button](https://primer.style/product/components/button/)

## 3. 目標與非目標

目標：

1. 老師打開首頁 5 秒內知道最優先的下一件工作。
2. 點名與評量只在一個主任動線上出現一次；每列固定顯示狀態、影響、期限與 CTA。
3. 點名頁區分「選擇狀態」與「確認送出」，送出後回饋扣堂／評量影響與成功或失敗。
4. 保留既有 API、角色權限、分校隔離、有效授課老師規則、請假狀態集合與扣堂契約。
5. 分析、排行、回饋統計與週課表仍可使用，但移到次要檢視或折疊區，不阻塞 priority data。

非目標：不改資料庫、不重寫 Attendance／LearningRecord domain service、不改評量核准或扣堂規則、不新增 gamification 規則、不把家長或主任權限下放給老師。

## 4. 目標工作流

```text
老師首頁 → 今日工作
       ├─ 待點名 → 點名頁 → 選狀態 → 確認點名 → 成功回饋／下一堂
       ├─ 待填／需修改評量 → 評量頁 → 儲存草稿／送出 → 回到清單
       ├─ 過期補填 → 評量頁指定堂次
       └─ 家長回饋 → 學習評量／回饋位置
```

每筆 `teacherTask` 統一為：

`id / type / severity / title / summary / count / owner / dueAt / actionLabel / target / source`

排序：`需修改／逾期 > 今日未完成 > 即將開始 > 已完成`；同級按 dueAt，再按課程時間。去重 key 使用 `type + sessionId/recordId`，不可讓同一堂課同時出現在任務中心、補填提醒與今日清單。

## 5. UI／UX spec

### TeacherHomePage

- 頁首只保留標題、日期／分校範圍、重新整理與必要的打卡狀態；移除行銷式 kicker、排行與連續使用訊息在主要工作區的競爭。
- 第一個面板為「今天要完成」單欄 task list。每筆固定顯示：任務類型、學生／科目、課程時間、狀態說明、期限與唯一 primary CTA。
- 頂部只保留一個未完成總數；不再同時顯示 mission count、pending attendance count、pending learning count、overdue badge 與 progress metric 來重複回答同一問題。
- 點名／評量／補填／回饋採明確動詞：`開始點名`、`填寫評量`、`修改評量`、`查看並回覆`。避免 `查看` 作為唯一動作。
- 今日無任務顯示「今天沒有待完成工作」與下一步（查看本週課表），而非空白卡片。
- 週課表、聊天、統計、同事軍階與 SystemTrust 改成次要區塊／延遲載入；分析失敗不可覆蓋主工作列表。

### AttendancePage

- 今日待點名是預設唯一主面板；歷史補登、補建堂次與主任老師打卡維持次要區塊。
- 每堂 mobile card 先呈現學生、科目、時段與「尚未點名」，下一行是狀態選擇，底部唯一 primary `確認點名`。`回報課表出入` 使用 secondary action。
- 批次到班明確標示「只會套用到已選堂次」，送出後顯示每堂成功／失敗，禁止以選取狀態代替送出成功。
- `leave_requested`、`leave`、`leave_adjusted`、`excused` 共享同一請假語意集合；請假中的堂次不出現在待點名或待填評量任務。
- loading、error、empty、權限不足與跨分校切換都有文字狀態、重試或下一步；錯誤不可只寫 console。

## 6. 資料／架構邊界

- 初始 priority load 只取既有 `class-sessions`、pending learning summary／overdue、clock-in 與工作台下方用來自我準備的整週課表；`feedback analytics`、`learning progress`、`ranks-for`、SystemTrust 與聊天未讀延遲至「更多工作資訊」開啟。
- 不新增 API；若現有 payload 不能產生 `dueAt` 或 target，先提供 `未提供期限` 的白話降級，不以前端猜測權限或日期。
- 將狀態映射放在純函式 `teacherDailyWorkflow.js`，測試排序、去重、請假排除、CTA 映射與 partial failure。
- API 呼叫使用 request generation／AbortController 或等效 stale response guard；分校切換後不能把上一校資料寫回目前畫面。
- 有效授課老師、代課、請假、評量與扣堂的真相仍由既有後端契約決定；UI 只呈現與導覽。

## 7. 驗收矩陣

- 狀態：normal、loading、empty、API error、部分資料失敗、長姓名／原因、100+ 堂 dense、權限不足、分校切換。
- Viewport：390、412、768、1280、1440px；`scrollWidth <= clientWidth`。
- 功能：首頁每類 task CTA、點名四種狀態、批次到班、回報出入、補建並點名、評量填寫／需修改、請假狀態不重複列出。
- A11y：鍵盤 tab 順序、focus ring、button name、`aria-live`、表格／卡片語意、modal Escape。
- 回歸：`sessionConsistency.test.js`、`LearningRecordLeaveExclusionTest`、effective teacher／代課點名權限、Attendance existing feature tests、現有 learning preview smoke。
- 交付：Vite build、lint、design guard、Playwright real Vue evidence、PR checks、merge、deploy、health、version、桌面／手機 production smoke。

## 8. 風險與 rollback

- 風險：把 analytics 延遲後可能影響使用者既有習慣；因此保留次要區塊與明確 loading，先不上線移除資料。
- 風險：去重若使用錯誤 key 可能漏掉同堂不同任務；純函式測試需覆蓋 record／session／feedback 三種來源。
- 風險：請假集合漏狀態會重現 R65；任何狀態變更需同步所有消費端。
- rollback：revert PR／merge SHA；無 schema migration，不做資料庫 rollback。

## 9. Definition of Done

老師從首頁可在一個清單完成今天的點名與評量工作；手機不需水平滑動找 CTA；所有狀態與錯誤可理解；既有後端真相、權限、扣堂與請假回歸全綠；production 有 health/version 與桌面／手機 evidence。未完成的分析區塊保留明確 Issue，不以畫面看似完成結案。

## 10. Implementation evidence

- `teacherDailyWorkflow.js` 集中處理待辦轉換、排序、去重、CTA 與請假排除。
- TeacherHomePage 已改為單一「今天要完成」隊列；分析、排行、SystemTrust 與聊天未讀放入可展開的次要檢視。
- AttendancePage 已加入老師專用的「先完成今日點名」入口，保留既有點名、批次、回報與權限契約。
- 單元測試、設計 token guard、lint、Vite build 與 real Vue Playwright 已在 feature worktree 通過；PR／CI／production 驗收尚待交付階段完成。
