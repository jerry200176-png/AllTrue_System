# 段考成績登記（考後待辦）— PRD

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 段考成績登記（Exam Round + Teacher Todo） |
| 版本 | v1.0 |
| 狀態 | Draft — 待 Founder 確認後進 DEV |
| 目標角色 | 主任／admin／super_admin（開考與進度）、老師（登分與待辦）、家長（P1 可查） |
| 文件日期 | 2026-10-17 |
| Risk Tier | **T2**（產品工作流；additive migration；不碰扣堂／繳費／auth 核心） |
| 關聯 | 既有 `Assessment`（學習檢測）並存、不取代；TeacherHome 待辦管線；`Student.ClassID` 年級 |

### 進度追蹤

| Phase | 角色 | 狀態 |
|---|---|---|
| Phase 1 [PLAN] | Product Manager | 進行中（本文件） |
| Phase 2 [ARCH] | Tech Lead | 待開始 |
| Phase 2b [UX] | UI/UX | 待開始 |
| Phase 2c [DBA] | DBA | 待開始 |
| Phase 3 [DEV] | 全端 | 待開始 |
| Phase 4 [TEST] | QA | 待開始 |
| Phase 4b [SEC] | Security | 待開始（PII 分數） |
| Phase 5 [REVIEW] | Staff | 待開始 |
| Phase 6 [DOCS] | Writer | 待開始 |
| Phase 7 [OPS] | DevOps | 待開始 |

---

## 2. 目標與業務背景

### 痛點（非技術）

段考結束後，老師常要在 Excel／LINE 回報學生「校內各科成績」。主任無法一眼看到誰還沒登完，家長也難在系統裡對齊同一份段考數字。現有「學習評量」是課堂後評語、`QuizScore` 是週考文字欄；「學習檢測」是補習班內測／診斷（側欄目前關閉），都不是「學校段考多科一次登完」的工作流。

### 業務價值

- 把「考後登成績」變成老師工作台正式待辦，降低漏登。
- 主任可開「這次段考」窗口並追蹤完成率。
- 成績與出勤／評量分開，不誤觸扣堂或帳單。

### KPI（上線後 4 週）

| 指標 | 目標 |
|---|---|
| 開放中段考窗口、老師有負責學生且已登至少一科的覆蓋率 | ≥ 80% |
| 窗口關閉日仍缺應登學生的比例 | ≤ 20% |
| 老師從待辦點進到完成一名學生多科登分的步驟 | ≤ 3 次主要操作 |
| 誤把段考成績寫進 LearningRecord／Assessment 的新問題 | 0 |

### 競品對齊摘要（§13 詳述）

台灣補教系統共識：

1. **成績分三類**：課內／**學校**／檢定（必亨）。
2. **一次考試多科**（雲太：最多約 10 科；必亨：學校類別下國英數等）。
3. **行政／主任先設考試，老師再登分**；可設登分時段（亞昕）。
4. **家長可查／可通知**（CARE、校掌雲、Aivora）— 本專案列 **P1**，P0 先完成登分與待辦。

---

## 3. 範圍

### In Scope（P0）

1. 主任在分校開「段考場次」（標題、考試日／登分截止日、適用年級、科目清單）。
2. 場次 `published` 後，老師工作台「今天要完成」出現 **段考待登** 待辦（依自己任教學生、缺分或缺完整科目）。
3. 老師對自己學生登／改各科分數（0–100，允許缺考標記）；不可跨校園、不可登非自己學生。
4. 主任看場次進度（已登／應登人數、按老師彙總）。
5. 側欄新增「段考成績」入口（主任＋老師）；與關閉中的「學習檢測」分開命名，避免混淆。
6. Additive DB + API + PHPUnit + 前端單元測試；change fragment + staff update。

### Out of Scope（明確不做）

- 班排／校排／高低均標／OMR 閱卷（政龍級功能）→ P2。
- 重考規則自動產生補考名單（必亨）→ P2。
- Excel 整批貼上／匯入（亞昕）→ P1。
- 家長入口查詢、LINE 成績通知 → **P1**（契約預留 `visible_to_parent`）。
- 改寫／取代 LearningRecord、Assessment、題庫、扣堂、繳費。
- 把 `assessment_type` 硬塞「段考多科矩陣」（見 Decision Log）。

---

## 4. RACI

| 角色 | 代表 | R/A/C/I |
|---|---|---|
| AI Agent（實作） | `[FEATURE]` | R/A |
| AI Agent（測試） | `[TEST]` | R |
| AI Agent（審查） | `[REVIEW]` | R |
| AI Agent（文件） | `[DOCS]` | R |
| AI Agent（部署） | `[OPS]` | R |
| 人類 | Founder / CEO | I（產品方向已由競品對齊鎖定；重大轉向才決策） |

## 4b. Dependencies

| 類型 | 說明 | 狀態 |
|---|---|---|
| 前置功能 | TeacherHome `buildTeacherTasks`、`StudentClass.TeacherID`、`Student.ClassID`、`require_campus` | 已存在 |
| 既有 Assessment | 並存參考；不依賴側欄重新開啟 | 已存在、nav 隱藏 |
| 外部服務 | 無 | — |
| Migration | 新表 `exam_rounds` / `exam_round_subjects` / `exam_scores`（名稱以 ARCH 定案為準） | 待 DEV |
| 環境 | 無新 secret；走 deploy.yml | — |

---

## 5. User Stories + AC

### US-001 主任開段考場次

As a 主任, I want 為本分校建立「113-1 第一次段考」並勾選科目與年級, so that 老師有統一的登分對象。

- AC-001a：`published` 後同校老師可在列表看到該場次。
- AC-001b：`draft` 不進入老師待辦。
- AC-001c：關閉（`closed`）後老師不可再新增／修改分數（主任可例外更正 — 見 FR-006）。

### US-002 老師考後待辦

As a 老師, I want 在教學工作台看到「段考待登」, so that 考後不會漏登自己學生。

- AC-002a：待辦人數 = 本人 `StudentClass.TeacherID` 且學生年級落在場次適用年級、尚有缺科或缺整筆的學生數。
- AC-002b：點 CTA 進入該場次登分頁，並聚焦未完成列。
- AC-002c：全部完成後該場次待辦從工作台消失（或 count=0）。

### US-003 老師登多科分數

As a 老師, I want 一次為學生填國／英／數等分數, so that 對齊學校段考單。

- AC-003a：只顯示場次科目欄；儲存後可再改至截止前／關閉前。
- AC-003b：分數必須在 0–100（整數或一位小數，ARCH 定案）；`absent` 標記時可不填分數。
- AC-003c：非本人學生回 403；跨校回 403。

### US-004 主任看進度

As a 主任, I want 看每位老師完成率, so that 知道催誰。

- AC-004a：場次詳情顯示應登／已登／完成％。
- AC-004b：可依老師分組。

---

## 5b. UI/UX 精緻化

沿用 AllTrue design tokens（`AtEmpty`、`AtButton`、TeacherHome 工作佇列樣式）；**禁止**新開一套儀表板風格。

### 頁面 A：段考場次列表（主任／老師）

| 面向 | 規格 |
|---|---|
| 版面 | 單一列表主區；主任右上「開新段考」；狀態 chip：草稿／開放中／已關閉 |
| 空狀態 | 圖示 `fact_check` +「還沒有段考場次」+ 主任 CTA；老師：「主任開放後會出現在這裡」 |
| 載入 | 列表 skeleton，避免 layout shift |
| 響應式 | 桌面表格式；手機卡片（對齊 AssessmentPage 既有 pattern） |
| 無障礙 | 狀態不只靠顏色；CTA ≥ 44px |

### 頁面 B：老師登分表（核心）

| 面向 | 規格 |
|---|---|
| 版面 | 學生列 × 科目欄矩陣；頂部場次標題＋截止日＋「還差 N 人」 |
| 互動 | 儲存單列或批次儲存；成功 toast；失敗 inline |
| 防呆 | 超範圍分數 inline 錯誤；關閉場次表單唯讀 |
| 空狀態 | 「你這次沒有需登分的學生」 |

### 區塊 C：TeacherHome 待辦

| 面向 | 規格 |
|---|---|
| 型別標籤 | `exam` →「段考成績」 |
| 排序 | 低於出缺勤／學習評量需修改，高於或並列家長回饋（`_sortRank` 由 ARCH 定） |
| CTA | 「去登分」→ 場次登分頁 |

### 頁面 D：主任進度（可與詳情同頁 tab）

完成率進度條 + 老師分組表；無資料時說明「尚無應登學生」。

---

## 6. 功能需求 FR

| ID | 需求 |
|---|---|
| FR-001 | 主任／admin／super_admin 可 CRUD 本分校段考場次（標題、考試日、登分截止、適用年級、科目清單、狀態）。 |
| FR-002 | 場次狀態機：`draft` → `published` → `closed`；publish／close idempotent。 |
| FR-003 | 老師僅能讀 `published`／`closed` 本分校場次；僅能寫 `published` 且未逾截止（若設截止）的本人學生分數。 |
| FR-004 | 應登學生定義：該校 `Student` 且 `ClassID`∈適用年級，且存在有效 `StudentClass` 其 `TeacherID`=該老師（狀態規則 ARCH 對齊現有在學過濾）。 |
| FR-005 | `GET /me/exam-pending-summary`（或等價）回傳老師待辦 count＋代表場次 id；TeacherHome 併入 `buildTeacherTasks`。 |
| FR-006 | 主任可在 `closed` 後更正分數並寫 audit；老師不可。 |
| FR-007 | 所有讀寫強制 `auth_campus_ids`；client 帶的 `campus_id`／`student_id` 必須 server 重驗。 |
| FR-008 | 側欄註冊 `exam-grades`（名稱以 nav 定案）；`roleFeatureMap` 文案區隔「學習檢測」。 |
| FR-009 | 不寫入 LearningRecord、不改 ClassSession、不觸發扣堂／帳單。 |
| FR-010 | 分數異動寫 audit（actor、before／after、reason 可選）。 |

---

## 7. 非功能需求 NFR

| ID | 需求 |
|---|---|
| NFR-001 | 老師待辦 summary API p95 &lt; 500ms（單校、老師學生 &lt; 200）。 |
| NFR-002 | 登分表一次載入該老師應登學生 ≤ 200；超過則分頁（ARCH）。 |
| NFR-003 | 降級：summary 失敗時 TeacherHome 不擋出缺勤／評量待辦（對齊 overdue 補充載入模式）。 |
| NFR-004 | Additive migration；rollback = 關 nav＋revert code；有資料時不 drop 表除非 Founder 批准。 |

---

## 8. 技術方向（禁止 code）

### 架構取捨

- **新 bounded context「ExamRound」**，與 Assessment／LearningRecord 並存。
- 理由：競品「學校多科一次登」是矩陣；現有 `assessment_results` 是單分數字，硬塞會扭曲診斷測驗產品。
- 老師範圍沿用 `StudentClass.TeacherID`（與代課：P0 **不含**代課老師代登段考；代課只影響當堂課）。
- TeacherHome 沿用 task 物件形狀 `{ id, type, severity, title, summary, count, actionLabel, target, source }`。
- 實作拆 PR：`(1) schema+API+tests` → `(2) 登分 UI+nav` → `(3) TeacherHome todo+summary`，每 PR &lt; 700 行、目標 ≤ 400。

### 資料概念（名稱 ARCH 可微調）

| 實體 | 用途 |
|---|---|
| ExamRound | 分校場次、狀態、日期、適用年級 |
| ExamRoundSubject | 場次科目（顯示名、排序、max_score 預設 100） |
| ExamScore | 學生×科目×分數／缺考；唯一鍵防重 |
| ExamScoreAuditLog | 異動稽核 |

### API 草圖（合約在 ARCH 定稿）

- 場次 CRUD／publish／close
- 場次學生登分列表（老師視角／主任視角）
- upsert 分數（單筆或小批次）
- `me/exam-pending-summary`
- 主任進度 summary

### 前端

- 新頁 `ExamGradesPage`（或等價）掛 `App.vue` `active`
- `teacherDailyWorkflow.js` 新 type `exam`
- `navigationRegistry.js` 顯示入口（不同於隱藏的 assessments）

---

## 8b. Decision Log

| 日期 | 決策 | 替代方案 | 理由 |
|---|---|---|---|
| 2026-10-17 | 主任開場次（1A），非老師各自建考 | 老師自建（1B）；純延用 Assessment | 競品（必亨／亞昕／雲太）皆行政設考、老師登分；「考後待辦」需要統一窗口 |
| 2026-10-17 | 學校多科矩陣（2A） | 只登補習班單科（2B）；兩者並行 | 「段考」在台語境＝校內多科；必亨明確分「學校」類 |
| 2026-10-17 | 新建 ExamRound，不擴充 AssessmentResult | 每科一個 Assessment；Assessment 加 JSON 多科 | 保留學習檢測邊界（RFC）；矩陣 UX 與單分 ledger 不同 |
| 2026-10-17 | 家長查詢／LINE 通知列 P1 | P0 同步做 | P0 先打通登分與待辦；CARE／校掌雲有家長端但可第二波 |
| 2026-10-17 | 待辦掛 TeacherHome，不另做待辦中心 | 只放側欄頁 | 與出缺勤／評量同一「今天要完成」心智模型 |
| 2026-10-17 | P0 不含代課老師代登段考 | 代課也可登 | 段考屬長期導師掌握校內成績；降低權限複雜度 |

---

## 9. 資安與存取控制

| 維度 | 評估 |
|---|---|
| Spoofing | Bearer + 既有 role middleware；無新公開端點 |
| Tampering | Server 重驗 campus／TeacherID／年級；audit 不可由 client 偽造 actor |
| Repudiation | ExamScoreAuditLog |
| Info disclosure | 老師不可列他班學生；403 不洩漏是否存在 |
| DoS | per_page 上限；批次 upsert 筆數上限 |
| Elevation | 老師不可 publish／close／closed 後更正 |

PII：學生成績屬敏感學習資料；日誌禁止打印完整成績清單；API 僅授權角色。

STRIDE 結論預標：**YELLOW**（PII）→ SEC 審查後需變 GREEN 才 merge。

---

## 10. QA 驗收

### Happy Path

1. 主任開場次 → publish → 老師待辦出現 → 登完所有科 → 待辦消失 → 主任進度 100%。
2. 老師改分後主任進度與列表一致。

### Edge

1. 學生無 `StudentClass` 或年級不符合 → 不進應登。
2. 截止日已過但仍 `published` → 老師唯讀（或依 FR 禁止寫入）。
3. 兩老師教同一學生不同課程 → 兩人待辦均可見該生（各自責任）；寫入不互斥（同一 ExamScore 列以 student×subject 唯一，後寫覆蓋＋audit）。

### Error

1. 跨校 student_id → 403。
2. 非法分數 → 422。
3. 對 closed 場次老師寫入 → 409／422。

### UI/UX 清單

- [ ] 空狀態有圖示＋說明＋CTA
- [ ] 非同步有 loading
- [ ] toast／inline 錯誤
- [ ] 響應式可用
- [ ] 待辦 type 標籤正確

---

## 11. 上線與維運

1. Feature branch → PR → CI 綠 → 依 RISK 政策 merge → `deploy.yml`。
2. Migration：additive；PR 宣告 `Additive-Migration: yes`；正式啟用仍遵守 deploy／Founder 邊界。
3. Feature 曝光：以 nav 註冊為開關；必要時 `.env` flag `EXAM_GRADES_ENABLED`（ARCH 決定是否需要）。
4. Observability：既有 Laravel log；登分失敗 rate 可後補。
5. Rollback：移除 nav＋revert deploy SHA；保留表資料。
6. Smoke：health；主任開一測試場次（測試分校）；老師帳號見待辦。

---

## 12. 優先級與里程碑

| 優先級 | 內容 | Agent |
|---|---|---|
| P0 | 場次＋多科登分＋TeacherHome 待辦＋主任進度＋測試＋SEC | `[FEATURE]` `[TEST]` `[REVIEW]` |
| P1 | Excel 匯入、家長可見、截止提醒 | 後續 PRD |
| P2 | 排名／均標、重考名單、LINE 推播 | 後續 |

不估日曆天數；依 PR 切片推進。

---

## 13. 風險／假設／開放問題

### 本專案既有

- Assessment RFC：診斷測驗 ≠ 出勤／扣堂；段考同樣必須隔離。
- TeacherHome 待辦為前端組裝＋summary API 模式（學習評量 pending summary）。

### 業界／競品（WebSearch 2026-10-17）

| 來源 | 可借鏡 |
|---|---|
| [必亨學務成績](https://web.phm.com.tw/classsystem_4affairs.jsp) | 成績三類（課內／學校／檢定）；學校多科；重考提醒（本專案 P2） |
| [雲太補教管理達人](https://www.unitesys.com.tw/unitesys/main/pro2-tupro.asp?m=1) | 一次最多多科、班排校排統計（排名 P2） |
| [亞昕成績管理](https://www.assota.com.tw/portfolio-item/%E6%88%90%E7%B8%BE%E7%AE%A1%E7%90%86%E7%B3%BB%E7%B5%B1-2/) | 定期考、登分時段、Excel 貼上（匯入 P1） |
| [CARE](https://www.careapp.cc/function.php) | 考試／作業成績＋家長 LINE／App（家長 P1） |
| [校掌雲](https://www.jretc.net/) | 成績通知與家長透明 |
| [TutorDesk Progress](https://databus.co/tutordesk/features/progress-tracking/) | 成績進老師工作流與趨勢報告 |
| [Tutorbase](https://tutorbase.com/solutions/test-prep-centers) | 分數追蹤與家長可見進步（偏考試班，借鏡待辦／可見性） |

### 假設

- AllTrue 學生年級以 `Student.ClassID` 可過濾場次適用對象。
- 「自己的學生」= 契約 `StudentClass.TeacherID`，不含代課。

### 開放問題（AI 可解或已鎖定）

| 問題 | 狀態 |
|---|---|
| 發起者／成績形狀 | **已鎖定** 1A＋2A（競品） |
| 同學生多位老師是否都能改同一科 | **已鎖定** 可寫＋audit 後寫勝出；P1 再考慮科目老師對應 |
| 預設科目清單 | `[AI-RESOLVABLE]` ARCH 用國英數自社（可設定） |
| 是否要 env feature flag | `[AI-RESOLVABLE]` 若 nav 即開關可免 flag |

---

## 14. Definition of Done

- [ ] FR-001～010 皆有對應 Feature／API 測試：驗證方式：CI PHPUnit 相關 suite 全綠
- [ ] TeacherHome 待辦 type `exam` 單元測試：驗證方式：`npm` 對應 test 檔 pass
- [ ] 多校區隔離測試：他校學生 403
- [ ] Presubmit／CI 全綠；PR 宣告 Risk T2 與 Additive-Migration（若有）
- [ ] `docs/changes/<date>-midterm-exam-grades.md` fragment 存在
- [ ] `docs/staff-updates/<id>.yml` 教職員可感知說明存在
- [ ] SEC STRIDE 無未清 HIGH
- [ ] REVIEW Critical 清空
- [ ] merge 後 deploy health：`curl` health → `status=ok`（有 deployable diff 時）

---

## Todos（九類）

| ID | 內容 | Agent | 狀態 |
|---|---|---|---|
| T1 | 後端：migration＋ExamRound API＋權限＋audit | `[FEATURE]` | pending |
| T2 | 前端：場次列表＋登分矩陣＋nav | `[FEATURE]` | pending |
| T3 | UI/UX：空狀態／loading／TeacherHome 待辦標籤（§5b） | `[FEATURE]` | pending |
| T4 | TeacherHome＋`exam-pending-summary` 串接 | `[FEATURE]` | pending |
| T5 | PHPUnit＋前端單元／workflow 測試 | `[TEST]` | pending |
| T6 | §10 QA 情境自動驗收 | `[TEST]` | pending |
| T7 | STRIDE／校園隔離資安審查 | `[REVIEW]` | pending |
| T8 | FR 對照 Code Review | `[REVIEW]` | pending |
| T9 | change fragment＋staff update＋INDEX／TECH 導航一行 | `[DOCS]` | pending |
| T10 | deploy.yml 後 health／smoke | `[OPS]` | pending |

---

## 附錄：對使用者一句話

> 主任開「這次段考」並選科目與年級 → 老師工作台出現待登名單 → 一次登完學校各科分數 → 主任看誰還沒交。學習檢測（補習班內測）繼續分開；家長查詢下一波再做。
