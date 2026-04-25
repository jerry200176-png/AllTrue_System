# [PLAN] PRD — 家長評量回饋給老師與主任查看

## 0. 根因（BugFix 專屬）
N/A。本次是新功能，不是 bug fix。

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | Parent Learning Record Feedback |
| 日期 | 2026-04-25 |
| 版本 | v1.0 |
| 狀態 | Draft / Phase 1 [PLAN] |
| 目標角色 | 家長、老師、主任 / 行政、super_admin |
| 入口 | 家長透過 LINE / 家長入口查看學生評量後送出回饋；老師與主任在系統內查看 |

## 2. 目標與業務背景

### 痛點
- 家長看完學習評量後，目前無法在同一個情境下回覆老師，只能透過 LINE / 電話 / 現場轉述。
- 老師收不到結構化回饋，主任也無法掌握家長是否有疑問或不滿。
- 回饋散落在私訊會造成交接困難，老師離職或代課時紀錄不可追蹤。

### 業務價值
- 讓家長在看完評量表後立即留下問題、感謝或補充資訊。
- 老師可在教學脈絡中看到家長回饋，改善下次上課準備。
- 主任可監控家長回饋品質與風險訊號，及早介入溝通。

### KPI
- 家長評量回饋提交成功率 >= 99%。
- 老師 / 主任查看回饋 API P95 < 500ms。
- 家長提交回饋後，老師端可見延遲 < 5 秒（重新整理後）。
- 所有回饋都必須可追溯到 `LearningRecord`、學生、老師、分校。

## 3. 範圍

### In Scope
- 家長在 ParentPortal 的評量詳情中新增「給老師的回饋」區塊。
- 家長可對單一已核准評量送出文字回饋。
- 老師可在 LearningRecordsPage 或教學工作台相關入口看到自己學生的家長回饋。
- 主任 / admin / super_admin 可依分校查看所有家長回饋。
- 回饋需要具備建立時間、更新時間、讀取狀態或未讀提示。
- 後端需新增授權檢查，確保家長只能對自己的學生評量送回饋。
- 新增測試覆蓋 parent auth、teacher visibility、director branch isolation。

### Out of Scope
- 老師回覆家長的雙向聊天。
- LINE 主動推播老師收到新回饋。
- 情緒分析、AI 摘要、敏感內容自動審核。
- 家長對老師打星等評分或公開評價。
- 匿名回饋。

## 4. RACI

| 角色 | 代表 | R/A/C/I |
|---|---|---|
| AI Agent（產品規劃） | `[PLAN]` Agent | A |
| AI Agent（架構） | `[ARCH]` Agent | R |
| AI Agent（UX） | `[UX]` Agent | R |
| AI Agent（DBA） | `[DBA]` Agent | R |
| AI Agent（實作） | `[FEATURE]` Agent | R |
| AI Agent（測試） | `[TEST]` Agent | R |
| AI Agent（資安） | `[SEC]` Agent | R |
| AI Agent（審查） | `[REVIEW]` Agent | R |
| AI Agent（文件） | `[DOCS]` Agent | R |
| AI Agent（部署） | `[OPS]` Agent | R |
| 使用者 / CEO | Jerry | I |

## 4b. Dependencies

| 類型 | 說明 | 狀態 |
|---|---|---|
| 前置 PR | 無必要前置 PR | 無依賴 |
| 外部服務 | LINE Login / 家長入口既有登入機制；本次不新增 LINE API scope | 已存在 |
| 環境 | 需要 DB migration 新增回饋資料表 | 待 [DBA] 設計 |
| 既有資料 | `ParentSession`、`LearningRecord`、`Student`、`StudentClass`、`User` | 已存在 |
| 權限 | 家長 session、teacher role、director/admin/super_admin role、require_campus | 待 [ARCH] 對齊 |

## 5. User Stories + AC

### US-001 家長送出回饋
As a 家長，我想在看完孩子的評量表後留下回饋 so that 老師知道我對本次學習狀況的疑問或補充。

AC:
- 家長只能在自己孩子的評量詳情中看到回饋區。
- 回饋內容必填，長度 1-500 字。
- 成功送出後顯示送出時間與「已送出給老師」提示。
- 若網路失敗，畫面保留輸入內容並顯示重試提示。

### US-002 家長修改回饋
As a 家長，我想修改剛送出的回饋 so that 可修正錯字或補充資訊。

AC:
- 同一筆評量同一家長 session 只保留一筆最新回饋。
- 家長可更新自己的回饋，不可修改其他家長或其他學生的回饋。
- 更新後老師與主任看到最新內容與更新時間。

### US-003 老師查看回饋
As a 老師，我想在評量列表或評量詳情看到家長回饋 so that 下次上課前能知道家長想法。

AC:
- 老師只能看到自己 `TeacherID` 相關評量的家長回饋。
- 有未讀回饋時，列表顯示明確 badge。
- 點開評量詳情可看到回饋內容、提交時間、學生姓名。

### US-004 主任查看回饋
As a 主任，我想查看本分校所有家長評量回饋 so that 可掌握家長問題與服務品質。

AC:
- 主任只能看到自己分校的回饋。
- super_admin 可依既有權限查看可管理分校。
- 回饋列表可依未讀、學生、老師、日期篩選。

### US-005 權限防護
As a 系統，我要拒絕越權讀寫 so that 學生資料與家長評論不外洩。

AC:
- 無 parent session 的請求回 401。
- parent session 不屬於該學生時回 403。
- 老師讀取非自己評量的回饋回 403 或不出現在列表。
- 跨分校主任不可看到其他分校回饋。

## 5b. UI/UX 精緻化

### ParentPortal 評量詳情

| 面向 | 規格 |
|---|---|
| 版面層次 | 在評量內容下方新增「給老師的回饋」卡片；標題 16px / semibold，內容 14px |
| 色彩一致性 | 沿用既有卡片背景與主色按鈕；成功狀態用既有 success token |
| 互動回饋 | 送出按鈕 loading 時不可重複點擊；成功 toast 3 秒 |
| 空狀態 | 尚未送出時顯示「有想補充給老師的嗎？」與 textarea |
| 載入狀態 | 評量詳情載入期間回饋區以 skeleton 或 spinner 顯示 |
| 防呆 | 空白不可送出；超過 500 字 inline 顯示剩餘字數；送出前不彈二次確認 |
| 響應式 | 手機寬度 textarea 滿版；按鈕觸控高度 >= 44px |
| 無障礙 | textarea 有 aria-label；錯誤訊息可被 screen reader 讀取 |

### LearningRecordsPage / 老師視角

| 面向 | 規格 |
|---|---|
| 版面層次 | 列表新增「家長回饋」小徽章；評量詳情新增回饋區塊 |
| 色彩一致性 | 未讀用 warning badge；已讀用 neutral badge |
| 互動回饋 | 點開評量詳情時標記為已讀；失敗時不阻擋評量檢視 |
| 空狀態 | 無回饋顯示「尚無家長回饋」 |
| 載入狀態 | 回饋摘要跟隨列表 API；詳情可 lazy load |
| 防呆 | 老師不可編輯家長回饋，只能查看 |
| 響應式 | 手機列表 badge 不造成水平捲動 |
| 無障礙 | badge 有文字，不只靠顏色 |

### Director / Admin 視角

| 面向 | 規格 |
|---|---|
| 版面層次 | 新增回饋列表或整合到 LearningRecordsPage 篩選區 |
| 色彩一致性 | 沿用既有表格與 filter style |
| 互動回饋 | 篩選條件變更後顯示 loading；查無資料顯示空狀態 |
| 空狀態 | 「目前沒有家長回饋」並說明回饋會在家長送出後出現 |
| 載入狀態 | 分頁載入不可卡住整頁 |
| 防呆 | 主任不可冒用家長新增或修改回饋 |
| 響應式 | 桌面優先；手機至少可閱讀內容 |
| 無障礙 | 表格欄位標題清楚，按鈕具 aria-label |

## 6. 功能需求 FR

| ID | 需求 |
|---|---|
| FR-001 | 系統應新增「家長評量回饋」資料模型，回饋必須綁定單一 LearningRecord。 |
| FR-002 | 系統應限制每筆 LearningRecord 每位家長身份最多一筆有效回饋。 |
| FR-003 | 家長應可建立與更新自己的回饋，內容長度限制 1-500 字。 |
| FR-004 | 家長只能對自己學生的已核准評量送出回饋。 |
| FR-005 | 老師應可讀取自己授課評量的家長回饋。 |
| FR-006 | 主任 / admin 應可讀取所屬分校的家長回饋。 |
| FR-007 | 回饋列表應支援未讀、學生、老師、日期篩選。 |
| FR-008 | 系統應記錄回饋建立 / 更新時間與最後讀取狀態。 |
| FR-009 | API 回應不得包含 parent token、電話完整值或不必要 PII。 |
| FR-010 | 前端應在 ParentPortal 評量詳情提供清楚輸入、送出、更新、錯誤狀態。 |

## 7. 非功能需求 NFR

| ID | 需求 |
|---|---|
| NFR-001 | 家長送出回饋 API P95 < 500ms。 |
| NFR-002 | 老師 / 主任查詢回饋列表 P95 < 800ms。 |
| NFR-003 | 回饋內容不得寫入一般 error log、console log 或 analytics event。 |
| NFR-004 | 所有 API 需走 HTTPS；不得把回饋內容放在 URL query string。 |
| NFR-005 | 若回饋 API 失敗，評量內容仍需正常顯示，回饋區顯示降級錯誤。 |
| NFR-006 | DB migration 必須可 rollback，不得 DROP 既有欄位。 |

## 8. 技術方向（禁止 code）

### 後端
- 新增家長評量回饋資料表，綁定 `LearningRecord`、`Student`、`TeacherID`、`CampusID`。
- 新增 parent 端 API：建立 / 更新 / 讀取單筆評量回饋。
- 新增 teacher / director API：查詢回饋摘要與列表。
- 權限以既有 `ParentSession`、role middleware、`require_campus` 為基礎。
- 多校區隔離以 `CampusID` 作為所有主任查詢的必要條件。

### 前端
- `ParentPortal.vue`：評量詳情新增回饋區。
- `LearningRecordsPage.vue`：評量列表 / 詳情顯示回饋 badge 與內容。
- 若主任需要獨立管理頁，可在既有 LearningRecordsPage 篩選區先提供 MVP，避免新增過多導航。

### 資料
- 建議資料表使用 snake_case 新表，例如 `learning_record_feedbacks`。
- 必要索引：`learning_record_id` unique 或 composite unique、`teacher_id`、`campus_id`、`created_at`。
- 不修改 `LearningRecord` 既有欄位，避免高風險回歸。

## 8b. Decision Log

| 日期 | 決策 | 考慮過的替代方案 | 選擇理由 |
|---|---|---|---|
| 2026-04-25 | 回饋綁定 LearningRecord，而不是獨立聊天串 | LINE 聊天 / 通知中心 thread | 本需求是「看完評量表後回饋」，綁評量可追溯、權限清楚 |
| 2026-04-25 | MVP 不做老師回覆家長 | 雙向留言 / 站內信 | 雙向溝通會擴大通知、稽核與客服責任；先做家長->老師/主任可見 |
| 2026-04-25 | 一筆評量一筆家長回饋，可更新 | 多筆留言串 | 降低 UI 複雜度與 spam 風險，符合「評論反饋」而非聊天 |
| 2026-04-25 | 新表保存，不改 LearningRecord | 在 LearningRecord 加欄位 | 新表可加讀取狀態與索引，降低對評量核心流程影響 |

## 9. 資安與存取控制

### Role / 權限
- Parent：只能透過有效 `ParentSession` 操作自己學生的評量回饋。
- Teacher：只能查看 `LearningRecord.TeacherID = auth_teacher_id` 的回饋。
- Director / Admin：只能查看所屬 `CampusID` 的回饋。
- Super Admin：遵循既有分校管理權限，不新增全域 bypass。

### PII
- 回饋內容可能含學生狀況、家庭資訊、電話、健康或情緒描述，視為敏感教育資料。
- API 不回傳 parent token、完整電話或 parent session hash。
- Log 禁止寫入回饋全文。

### STRIDE 快評

| 類型 | 風險 | 等級 | 控制 |
|---|---|---|---|
| Spoofing | 非家長冒用 parent session 送回饋 | 高 | 驗證 ParentSession + TokenHash + ExpiresAt |
| Tampering | 家長修改非自己學生回饋 | 高 | 後端以 session->student 關聯驗證，不信任前端 student_id |
| Repudiation | 家長否認曾送出回饋 | 中 | 記錄 created_at / updated_at / actor 類型 / IP hash（若既有可用） |
| Information Disclosure | 老師或主任跨分校看到回饋 | 高 | teacher_id / campus_id scope 測試必覆蓋 |
| Denial of Service | 家長重複送大量回饋 | 中 | 每筆評量一筆回饋 + rate limit |
| Elevation of Privilege | 公開 parent API 被用來讀任意 LearningRecord | 高 | parent endpoint 必須逐筆驗證學生所有權 |

## 10. QA 驗收

### Happy Path
- 家長登入 ParentPortal，打開孩子一筆已核准評量，輸入回饋並成功送出。
- 老師登入後在該評量看到家長回饋 badge 與內容。
- 主任登入本分校後可在回饋列表看到該筆回饋。

### Edge Cases
- 家長嘗試對其他學生 LearningRecord 送回饋，API 回 403。
- 家長對 pending / rejected / voided 評量送回饋，API 拒絕。
- 回饋內容空白、純空白、超過 500 字，前後端都拒絕。
- 同一評量重複提交，應更新既有回饋，不新增多筆有效資料。
- 老師跨分校支援時，只看到自己授課評量的回饋。

### Error Cases
- parent session 過期：回 401，前端提示重新登入。
- API 500：評量內容仍顯示，回饋區顯示「暫時無法送出，請稍後再試」。
- 網路中斷：textarea 內容保留，不清空。

### UI/UX 驗收清單
- [ ] 空狀態有圖示 + 說明 + CTA。
- [ ] 所有非同步操作有 loading 狀態。
- [ ] 成功 / 失敗操作有 toast 或 inline 回饋。
- [ ] 表單防呆包含必填、字數、空白 trim。
- [ ] 手機觸控目標 >= 44px，無水平 overflow。
- [ ] 回饋 badge 不只靠顏色表達狀態。

## 11. 上線與維運

### 部署步驟
1. Feature branch 開發。
2. Migration / tests / frontend 完成後 push PR。
3. CI 全綠後 merge。
4. Deploy workflow 自動部署。
5. 若有 migration，僅在 PR merge 後由合法部署流程執行。
6. 部署後 `GET /api/v1/health` 必須 `status=ok`。

### Feature Flag 策略
- 建議後端以 config 或 DB setting 控制 parent feedback UI/API 是否開啟，預設開啟前可先內部測。
- 若現有系統沒有 feature flag 基礎，MVP 可透過 PR rollback 關閉 UI 入口，但 API 權限仍需完整。

### Observability

| 監控項目 | 指標 / log 關鍵字 | 告警閾值 | 負責 Agent |
|---|---|---|---|
| 回饋提交錯誤率 | feedback submit 4xx/5xx count | 5xx > 3 次 / 10 分鐘 | `[OPS]` |
| 權限拒絕 | feedback 403 count | 異常暴增需查 | `[SEC]` |
| API latency | P95 submit / list | > 800ms | `[SRE]` |
| 健康檢查 | `/api/v1/health` | 非 200 或 status != ok | `[OPS]` |

### 回滾
- 無資料破壞性變更；若 UI 造成問題，先 revert frontend 入口。
- 若 API / migration 需 rollback，使用 `git revert <merge_commit>` 建 PR；migration down() 僅移除新表，不碰既有資料表。
- 預估回滾時間：10-20 分鐘（不含 CI 排隊）。

## 12. 里程碑與優先級

| 優先級 | 項目 | Agent |
|---|---|---|
| P0 | 權限模型、parent ownership、CampusID 隔離 | `[ARCH]` / `[SEC]` |
| P0 | DB schema + migration rollback | `[DBA]` |
| P0 | Parent submit API + teacher/director read API | `[FEATURE]` |
| P0 | Pest tests：parent 403、teacher scope、director branch scope | `[TEST]` |
| P1 | ParentPortal 回饋 UI | `[FEATURE]` / `[UX]` |
| P1 | 老師 / 主任回饋顯示與未讀提示 | `[FEATURE]` / `[UX]` |
| P1 | STRIDE 審查與 code review | `[SEC]` / `[REVIEW]` |
| P2 | CHANGELOG / 使用說明補充 | `[DOCS]` |
| P2 | 部署後 health check 與 smoke test | `[OPS]` |

### Todos（9 類）
- [ ] 後端 API / 資料：新增回饋資料表與 parent/teacher/director API。`[FEATURE]`
- [ ] 前端 UI 功能：ParentPortal、LearningRecordsPage 顯示與串接。`[FEATURE]`
- [ ] UI/UX 精緻化：完成第 5b 節所有空狀態/loading/防呆/響應式。`[FEATURE]`
- [ ] 測試設計與自動執行：新增 Pest feature tests。`[TEST]`
- [ ] 自動化 QA 驗收：逐條執行第 10 節場景。`[TEST]`
- [ ] 資安靜態審查：STRIDE + 權限邊界。`[SEC]`
- [ ] Code Review：逐條對照 FR + 多校區隔離。`[REVIEW]`
- [ ] 文件更新：CHANGELOG；如有家長操作流程需補使用說明。`[DOCS]`
- [ ] 部署與 health check：CI 綠、merge、deploy、health check。`[OPS]`

## 13. 風險 / 假設 / 開放問題

### WebSearch 摘要
搜尋教育資料與 EdTech privacy best practices 後，重點為：資料最小化、角色權限、審計紀錄、保留期限、避免敏感資料進入 log、加密傳輸、明確說明資料用途。來源包含 U.S. Department of Education Student Privacy、EdTech privacy guides。

| 風險 | 等級 | 業界標準解法（來源） | 本專案採行方式 |
|---|---|---|---|
| 家長回饋包含敏感教育 / 家庭資訊 | 高 | Data minimization、RBAC、audit trails（U.S. Department of Education / EdTech privacy guides） | 只收 1-500 字必要內容；不進 log；RBAC + parent ownership |
| 公開 parent endpoint 越權讀寫 | 高 | Strong authentication and scoped access（StudentPrivacy.ed.gov / FERPA guidance） | ParentSession + TokenHash + ExpiresAt + student ownership check |
| 老師 / 主任跨分校看到資料 | 高 | Role-based access and school scoped access（EdTech privacy guides） | TeacherID + CampusID scope；測試覆蓋 |
| 回饋變成客服聊天，超出老師負荷 | 中 | Clear product boundaries and notification preferences | MVP 不做雙向聊天，不做即時 push |
| 家長輸入攻擊內容或 HTML | 中 | Input validation and output escaping | 後端 trim + length limit；前端以 text rendering 顯示，不渲染 HTML |
| 資料保留過久 | 中 | Retention policy not forever by default | v1 跟 LearningRecord 生命週期保留；後續可加封存策略 |

### 假設
- 假設 ParentPortal 已能列出已核准 LearningRecord。若不成立，[AI-RESOLVABLE] 由 Agent 讀 ParentPortal 現況並補 API 契約。
- 假設 `ParentSession` 可解析到 student scope。若不成立，[AI-RESOLVABLE] 讀既有 parent login controller。
- 假設 director/admin 的分校權限已由 `require_campus` 處理。若不成立，[AI-RESOLVABLE] 於 [ARCH] 補明確 scope。

### 開放問題
- [AI-RESOLVABLE] 回饋是否應支援多位家長同一學生分別留言：先查 ParentSession 模型是否能識別 parent identity；v1 預設一筆評量一筆家長回饋。
- [AI-RESOLVABLE] 主任入口放在 LearningRecordsPage 還是新增獨立頁：先評估現有導航與頁面複雜度。
- [BLOCKED: 業務策略] 是否要開放老師回覆家長。v1 建議不做，避免擴大成客服聊天。

## 14. Definition of Done

- [ ] DB migration 安全：驗證方式：CI migration 在 test DB 成功，且 migration down() 不包含 DROP 既有表 / 欄位。
- [ ] Parent submit API：驗證方式：Pest 測試 parent 可對自己學生已核准評量送出回饋，回 200/201。
- [ ] Parent ownership guard：驗證方式：Pest 測試 parent 對非自己學生評量送出，回 403。
- [ ] Teacher visibility：驗證方式：Pest 測試老師只看見自己 `TeacherID` 評量回饋。
- [ ] Director branch isolation：驗證方式：Pest 測試主任不可看到其他 `CampusID` 回饋。
- [ ] Frontend build：驗證方式：GitHub Actions Vite build success。
- [ ] PHPUnit：驗證方式：GitHub Actions PHPUnit Feature & Unit Tests success。
- [ ] UI/UX：驗證方式：[REVIEW] 逐條對照第 5b / 第 10 節，無缺項。
- [ ] STRIDE：驗證方式：[SEC] 審查無 HIGH 風險未處理。
- [ ] CHANGELOG：驗證方式：diff 包含 `docs/CHANGELOG.md` 新增 Fixed/Added 條目。
- [ ] Deploy：驗證方式：merge 後 deploy workflow success，`curl -sk https://daan.lifenet.com.tw/api/v1/health` 回 `status: ok`。

## Exit Checklist — Phase 1 [PLAN]

- [x] PRD 14 節完整。
- [x] 包含 9 類 Todos。
- [x] 涉及前端，已填 UI/UX 精緻化。
- [x] 涉及 PII / parent auth，已列 STRIDE。
- [x] 第 13 節已先 WebSearch。
- [x] 未修改 production code。

等待使用者批准進入 Phase 2 [ARCH]。
