---
name: 課程管理精緻化PRD
overview: 規劃一份以 PM 視角撰寫的 PRD，聚焦 `CourseManagement.vue` 與 `StudentsList.vue` 的課程資訊呈現重構，尤其是已完課/已結算/已暫停狀態的 UI 精緻化、資訊層級與操作動線優化。此計畫以視覺與互動品質提升為主，避免改動既有高風險堂次/請假/調課商業邏輯。
todos:
  - id: backend-api-scope
    content: "[FEATURE] 後端 API / 資料：本次預設不調整商業邏輯；若 UI 定稿後需要補展示欄位，再評估是否新增輕量唯讀欄位，且不得改動 `closed_reason` / `Stop` / 堂次規則。"
    status: completed
  - id: frontend-ui-feature
    content: "[FEATURE] 前端 UI（功能）：重構 `CourseManagement.vue` 與 `StudentsList.vue` 的課程列表資訊架構，將進行中與歷史課程分區，重新整理狀態、費用、堂數、時段與操作區層級。"
    status: completed
  - id: frontend-ui-polish
    content: "[FEATURE/UI-UX] UI/UX 精緻化：依 PRD 第 5b 節完成空狀態、loading、toast、不可操作提示、色彩層級、間距與 mobile 版型精緻化。"
    status: completed
  - id: test-design
    content: "[TEST] 測試設計：為 `completed` / `settled` / `inactive` / `low sessions` / `PackageID` 等組合設計手動驗收與必要回歸測試，覆蓋桌機與手機版。"
    status: completed
  - id: qa-acceptance
    content: "[QA] 依 PRD 第 10 節執行 Happy Path / Edge / Error 驗收，並完成 UI/UX 驗收清單逐項確認。"
    status: completed
  - id: security-review
    content: "[REVIEW/資安] 確認本次僅為呈現層改造，未新增越權入口、未擴大 PII 暴露，且不可操作課程仍維持正確限制。"
    status: completed
  - id: code-review
    content: "[REVIEW] 針對前端重構做 code review，重點檢查兩頁狀態語意一致性、元件重用性與 mobile 響應式品質。"
    status: completed
  - id: docs-update
    content: "[DOCS] 更新 `docs/CHANGELOG.md`，必要時補充課程管理/學生頁的新 UI 說明與操作差異。"
    status: completed
  - id: deploy-release
    content: "[Ops] 部署與上線：若有前端改動，執行 `cd /home/admin/frontend && npm run deploy`，並確認 `index.html` 與 assets 同步。"
    status: completed
  - id: uiux-signoff
    content: "[UI/UX Designer] 依 PRD 第 5b 與第 10 節完成視覺與互動品質 sign-off，特別確認已完課呈現不再只是灰底殘留列。"
    status: completed
  - id: pm-signoff
    content: "[PM] 確認本次交付已達成「課程管理精緻化」目標，並完成範圍、驗收與優先級 sign-off。"
    status: completed
isProject: false
---

# 課程管理 UI 精緻化與已完課呈現重構 PRD

## 1. 文件資訊
- 功能名稱：課程管理頁與學生頁課程區塊 UI 精緻化
- 版本 / 日期：v0.1 / 2026-04-16
- 狀態：Draft
- 目標角色：主任、櫃檯；次要影響老師（閱讀課程資訊時）

## 2. 目標與業務背景
- 目前痛點：課程列表把「進行中 / 已暫停 / 已完課 / 已結算」都放在相同表格結構中，只靠灰底與小標籤區分，資訊層級不清楚。從你提供的畫面看，已完課課程雖然 technically 有被標記，但視覺上像是殘留列，不像一個被完整收束的歷史狀態。
- 業務價值：提升主任在學生頁與課程管理頁的掃讀效率，降低誤操作，讓「可繼續處理的課程」與「歷史課程」一眼可分。
- 成功指標（KPI）：
  - 主任可在 3 秒內區分進行中課程與歷史課程。
  - 已完課 / 已結算課程的狀態與可操作性零歧義。
  - 同一位學生的課程閱讀順序更清楚，減少點錯 `操作` / `詳情` 的情況。
  - 手機與桌面版都能維持一致的狀態辨識邏輯，不依賴灰底單一提示。

## 3. 範圍
- In Scope：
  - `[frontend/src/pages/CourseManagement.vue](/home/admin/frontend/src/pages/CourseManagement.vue)` 的學生分組課程列表重構。
  - `[frontend/src/pages/StudentsList.vue](/home/admin/frontend/src/pages/StudentsList.vue)` 的學生課程表格重構。
  - 已完課、已結算、已暫停、低堂數、方案課程等 badge / row / summary 呈現規則重設。
  - 課程列操作區的 CTA hierarchy、狀態文案、空狀態、loading、互動回饋精緻化。
  - 課程列表桌機與手機版的閱讀順序、密度與可操作性優化。
- Out of Scope：
  - 不改堂數扣除、請假/補課、評量、繳費、closed_reason 商業邏輯。
  - 不改後端資料計算規則，除非 UI 需要非常輕量的展示欄位補充。
  - 不重做整站 design system，只在課程管理相關區域建立可延用的樣式規格。

## 4. RACI
- PM：A
- CTO / 工程：R
- UI/UX Designer：R
- QA：R
- 資安：C
- IT / Ops：I
- UI/UX Designer 職責說明：負責定義課程狀態的視覺層級、表格/卡片排版、空狀態、loading、toast、操作危險程度與 mobile 響應式規格，並於交付前完成 sign-off。

## 5. User Stories
- As a 主任, I want 在學生課程列表一眼看出哪些課程還在進行、哪些已完課或已結算, so that 我不會把歷史課程當成待處理課程。
  - Acceptance Criteria：
  - [ ] 使用者不需要打開 `詳情`，即可在列表層辨識課程狀態。
  - [ ] 已完課與已結算不只靠顏色，還有清楚文字與資訊區塊差異。
- As a 櫃檯, I want 已完課課程顯示為「歷史課程」型態而非一般課程列, so that 我知道它不應再被當成日常操作對象。
  - Acceptance Criteria：
  - [ ] 已完課課程預設弱化主要 CTA，保留必要查看入口。
  - [ ] 歷史課程資訊可收合或分區，不與進行中課程混雜。
- As a 主任, I want 課程管理頁的狀態、費用、堂數、時段、操作有明確層級, so that 我能快速做續班、補登、查看詳情等判斷。
  - Acceptance Criteria：
  - [ ] 主要資訊與次要資訊有視覺層級區分。
  - [ ] 列表中的危險操作、次要操作、只讀操作清楚分組。

## 5b. UI/UX 精緻化需求
- 受影響頁面：`CourseManagement.vue`
  - 版面層次：學生區塊 header、進行中課程、歷史課程需三層資訊分明；課程列需將「科目/狀態」視為第一層，「老師/時段/費用」視為第二層，「備註/方案/例外警示」視為第三層。
  - 色彩一致性：進行中使用既有主色與成功/警示色；已暫停、已完課、已結算需有各自穩定色語意，但歷史課程不可只做低對比灰化，避免看起來像 disabled bug。
  - 互動回饋：`詳情`、`新增堂次`、`操作` 的 hover/active/loading 狀態需明確；不可操作原因需以 tooltip 或 inline hint 呈現，不可只靠 disabled。
  - 空狀態設計：某學生無進行中課程時，顯示「目前沒有進行中課程」與 CTA；若只有歷史課程，需有可閱讀的歷史區塊說明。
  - 載入狀態：學生群組展開時，課程列應有 skeleton 或 placeholder，避免資料突然跳動。
  - 防呆設計：已完課/已結算的不可操作行為需有明確原因提示；危險操作需二次確認。
  - 響應式 / 行動裝置：手機上不應沿用桌面表格硬縮，應改為 card/stacked row 語意，維持狀態標籤與 CTA 的觸控可讀性。
- 受影響頁面：`StudentsList.vue`
  - 版面層次：學生主資訊與課程資訊需更明確分離；課程表格中的「狀態」不可散落在科目欄小 tag 中。
  - 色彩一致性：低堂數、已暫停、已完課、已結算、方案課程各自語意一致，與 `CourseManagement.vue` 共用。
  - 互動回饋：保留 `操作` 入口但弱化歷史課程的主 CTA；若有歷史課程群組，應支援展開/收合。
  - 空狀態設計：學生無課程、只有歷史課程、只有暫停課程三種情境需分別設計文案。
  - 載入狀態：展開學生課程區塊時避免表格閃現與 layout shift。
  - 防呆設計：不可對歷史課程執行日常操作時，需有一致說明。
  - 響應式 / 行動裝置：課程資訊在窄螢幕需以可掃讀段落呈現，不保留過多橫向欄位。

## 6. 功能需求（FR）
- FR-001：系統應將「進行中課程」與「歷史課程（已完課 / 已結算）」在列表結構上分區，而非僅以 row 樣式差異表示。
- FR-002：系統應為 `已暫停`、`已完課`、`已結算` 定義清楚且一致的狀態 badge、文案與次級說明。
- FR-003：系統應重新定義課程列資訊層級，使科目、課程型態、狀態、老師、時段、費用、堂數、備註的視覺權重一致且可掃讀。
- FR-004：系統應將主要操作、次要操作、唯讀查看動作分組，並對不可操作狀態提供明確原因提示。
- FR-005：系統應在學生頁與課程管理頁共用一致的狀態視覺語言與元件規格。
- FR-006：系統應針對無進行中課程、只有歷史課程、只有暫停課程等情境提供明確空狀態與引導。
- FR-007：系統應提供手機友善的課程資訊版型，不讓狀態辨識依賴桌面表格縮排。
- FR-008：系統應維持現有 `closed_reason`、`Stop`、剩餘堂數與課程可操作限制的商業邏輯不變，僅優化呈現層。

## 7. 非功能需求（NFR）
- API 與頁面互動不應新增明顯等待感；既有列表載入體驗需維持在可接受範圍內。
- 不可因 UI 重構引入大面積 layout shift。
- 新樣式需支援淺色/深色主題延伸性，避免只針對當前單一背景調色。
- 若資料未齊全，需優雅降級顯示，不可因單欄位缺失導致整列排版崩壞。

## 8. 技術方向
- 受影響頁面：`[frontend/src/pages/CourseManagement.vue](/home/admin/frontend/src/pages/CourseManagement.vue)`、`[frontend/src/pages/StudentsList.vue](/home/admin/frontend/src/pages/StudentsList.vue)`、`[frontend/src/styles.css](/home/admin/frontend/src/styles.css)`。
- 關鍵現況：
  - `CourseManagement.vue` 目前在同一列中直接顯示 `已暫停 / 已結算 / 已完課` tag，且歷史課程主要只靠 `.course-settled td` 灰化處理。
  - `StudentsList.vue` 同樣在表格列中以 `tag-settled` 顯示 `已完課 / 已結算`，並用 `.course-settled-row td` 直接整列灰化。
- 架構選擇：
  - 優先走前端 UI 架構重整與共用狀態呈現規格，不先擴大成後端改版。
  - 可考慮抽離共用的課程狀態 badge / 課程摘要 row / 歷史課程區塊元件，避免兩頁再次分岔。
  - 若需要 migration：本次預設不需要；若 UI 設計後發現需要補「結束日期 / 結束原因文字 / 最近上課時間」等展示欄位，再另開後端子任務評估。
- 子任務 Agent 派發：
  - `[FEATURE]`：前端 UI 架構重整與必要 API 展示欄位補強。
  - `[TEST]`：設計回歸測試與手動驗收案例。
  - `[REVIEW]`：檢查 UI 一致性、狀態語意與不可操作規則是否被破壞。
  - `[DOCS]`：更新變更紀錄與操作說明。

## 9. 資安與存取控制
- 角色：沿用既有 director / teacher 權限，不新增新權限。
- PII：畫面仍會顯示學生姓名、老師姓名、課程資料，屬既有授權範圍；本次不新增敏感資料暴露。
- 稽核：若新增「歷史課程查看詳情」或狀態說明，不需稽核；若 UI 帶動操作文案變更，需確認不影響既有危險操作確認流程。
- STRIDE 快評：
  - Spoofing：低，無新登入或身份流程。
  - Tampering：中，需防止 UI 重構誤導使用者操作到不該操作的課程。
  - Information Disclosure：低，不新增資料範圍。
  - Denial of Service：低。

## 10. QA 驗收標準與測試計畫
- FR-001 / FR-002：
  - Happy Path：同一學生同時有進行中、已暫停、已完課、已結算課程時，能清楚分區與辨識。
  - Edge Case：只有歷史課程；只有暫停課程；同時有方案課程與低堂數課程。
  - Error Case：資料缺少老師、時段、備註時，版型仍穩定。
  - 回歸測試：不得影響 `closed_reason` 的既有語意，尤其 `completed` 與 `settled` 的顯示與不可新增堂次規則。
- FR-003 / FR-004 / FR-005：
  - Happy Path：主要資訊與操作清楚分層；兩頁狀態表現一致。
  - Edge Case：已完課課程保留查看入口但不誤導為可繼續編輯；低堂數課程仍保持提醒優先度。
  - Error Case：disabled 操作有可理解原因，不出現純灰按鈕無說明。
  - 回歸測試：不得破壞課程管理既有 `schedule_drift`、`contract_exception_count`、付款狀態、剩餘堂數提示。
- FR-006 / FR-007 / FR-008：
  - Happy Path：手機版可掃讀、可點擊、無水平 overflow。
  - Edge Case：極長學生姓名、極長備註、雙時段、多科方案。
  - Error Case：慢速載入時 skeleton / loading 不閃爍、無大幅跳動。
  - 回歸測試：不得影響既有補課、請假、課堂 chip 顯示與堂次 warning 的商業邏輯。
- UI/UX 驗收清單：
  - [ ] 空狀態有圖示 + 說明 + CTA，非空白或純文字
  - [ ] 所有非同步操作有 loading 狀態，無 layout shift
  - [ ] 成功 / 失敗操作有明確 toast 或 inline 回饋
  - [ ] 表單與操作防呆提示位置與措辭一致
  - [ ] 色彩 / 間距 / 字型層次符合既有 design token
  - [ ] 危險操作有二次確認 dialog
  - [ ] 行動裝置觸控目標足夠，無水平 overflow

## 11. 上線與維運
- 部署步驟：若修改前端頁面或樣式，依專案規則執行 `cd /home/admin/frontend && npm run deploy`。
- 監控項目：上線後重點觀察課程管理頁是否出現樣式斷裂、手機 overflow、按鈕不可點誤判。
- 回滾方案：以前端版本回滾為主，不涉及 schema 變更時可直接回退前端部署版本。

## 12. 里程碑與優先級
- P0（Must Have）：
  - 重構已完課 / 已結算 / 已暫停狀態呈現與資訊分區
  - 重整課程列資訊層級與 CTA hierarchy
  - 建立手機可讀版型與空狀態
- P1（Should Have）：
  - 抽出跨頁共用的課程狀態 UI 規格或元件
  - Skeleton / loading / microcopy 精緻化
- P2（Nice to Have）：
  - 補上更強的歷史課程摘要，例如結束原因、最後上課日、累積完成堂數摘要

## 13. 風險、假設、開放問題
- 風險（高）：UI 重構時若動到狀態判斷，可能誤傷 `closed_reason` 與不可操作限制。緩解：明確限定本次以呈現層為主。
- 風險（中）：兩頁目前實作分散，若不抽共用規格，後續又會長回不一致。緩解：至少先制定共用狀態 token 與版型規則。
- 風險（中）：手機版若保留桌面表格思維，會導致精緻化失敗。緩解：將手機視為獨立閱讀模式規劃。
- 假設：現有 `effectiveClosedReason(course)` 足以區分 `completed` 與 `settled`，短期不需後端補欄位。
- 假設：使用者當前優先要解的是視覺與閱讀品質，而非流程邏輯改造。
- 開放問題：[TODO: 需確認] 是否希望歷史課程預設收合，或仍預設展開但另成一區。Owner：PM / 使用者。
- 開放問題：[TODO: 需確認] 是否需要在學生頁加入「只看進行中課程」切換。Owner：PM。

## 14. Definition of Done
- [ ] 所有 FR 通過 QA 驗收
- [ ] UI/UX 驗收清單全部完成，且 UI/UX Designer sign-off
- [ ] 資安審查無阻擋項
- [ ] 前端已 deploy，且畫面與互動驗證正常
- [ ] `docs/CHANGELOG.md` 更新
- [ ] PM sign-off
- [ ] CTO / 工程 Lead sign-off

## 參考現況
- `CourseManagement.vue` 目前直接以單列 tag 呈現狀態，且歷史課程依賴灰底 row：

```133:146:/home/admin/frontend/src/pages/CourseManagement.vue
<tr :class="['course-row', courseRowClass(c)]">
  <td class="td-subject">
    <div class="subject-line">
      <span class="tag subject-tag" :class="{ 'subject-tag--paused': c.status === 'inactive' }">{{ getSubjectLabel(c.subject) }}</span>
      <span v-if="c.status === 'inactive' && !effectiveClosedReason(c)" class="tag tag-paused">已暫停</span>
      <span v-else-if="effectiveClosedReason(c) === 'settled'" class="tag tag-settled">已結算</span>
      <span v-else-if="effectiveClosedReason(c) === 'completed'" class="tag tag-settled">已完課</span>
```

- `StudentsList.vue` 同樣把歷史課程壓在一般表格列中：

```222:229:/home/admin/frontend/src/pages/StudentsList.vue
<tr v-for="course in getStudentCourses(student.id)" :key="course.id" :class="{ 'course-settled-row': effectiveClosedReason(course) === 'settled' || effectiveClosedReason(course) === 'completed' }">
  <td>
    <span class="tag">{{ getSubjectLabel(course.subject) }}</span>
    <span v-if="effectiveClosedReason(course) === 'settled'" class="tag tag-settled">已結算</span>
    <span v-else-if="effectiveClosedReason(course) === 'completed'" class="tag tag-settled">已完課</span>
    <span v-else-if="course.status === 'inactive'" class="tag tag-paused-sm">已暫停</span>
```

- 目前歷史課程樣式過度依賴灰底：

```3825:3851:/home/admin/frontend/src/pages/CourseManagement.vue
.course-settled td {
  background: #f9fafb;
  color: #9ca3af;
}
.tag-settled {
  background: #f3f4f6;
  color: #6b7280;
  border: 1px solid #d1d5db;
}
```

```2767:2787:/home/admin/frontend/src/pages/StudentsList.vue
.course-settled-row td {
  background: #f9fafb;
  color: #9ca3af;
}
.tag-settled {
  background: #f3f4f6;
  color: #6b7280;
  border: 1px solid #d1d5db;
}
```

## 建議執行順序
1. 先由 UI/UX Designer 定義「進行中 vs 歷史課程」的資訊架構與 mobile 版型。
2. 再由 `[FEATURE]` 將 `CourseManagement.vue` 與 `StudentsList.vue` 套用共用狀態規格。
3. 接著由 `[TEST]` 設計手動驗收與回歸檢查，特別覆蓋 `completed / settled / inactive / low sessions / package` 組合。
4. 最後由 `[REVIEW]` 檢查兩頁語意是否一致，並由 `[DOCS]` 更新變更紀錄。