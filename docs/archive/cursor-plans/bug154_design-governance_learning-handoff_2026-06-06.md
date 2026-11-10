# PRD：in-app #154 介面治理與評量「上一堂摘要」

> Tier：**T2（產品流程 + 前後端契約）**  
> 來源：in-app bug report `#154`（已分流到 GitHub `#684`、`#685`）  
> 狀態：**待 CEO 批准（Phase 1 [PLAN] 完成）**

---

## 0. 根因（BugFix 專屬，#154 為需求混合單）
- 這張回報不是單一程式錯誤，而是「同一張單混合三類需求」：
  1) 全站視覺一致性與質感治理（設計治理）  
  2) 學習評量填寫時缺少「上一堂內容」脈絡（教學連貫性）  
  3) docs 更新提醒（已完成）  
- 既有系統可用，但在「體驗一致性」與「代課交接可見性」上仍有缺口，造成「每次都要翻歷史」的作業成本。

## 1. 文件資訊
- 文件 ID：`PRD-bug154-design-learning-handoff-2026-06-06`
- 作者：AI `[PLAN]` Agent
- 日期：2026-06-06
- 目標角色：`super_admin` / `director` / `teacher`
- 相關工單：
  - Epic：`#684`（全站 UI 去 AI 感、逐頁治理）
  - Feature：`#685`（評量表上一堂摘要）
- 關聯文件：
  - `docs/RULE_DESIGN_SYSTEM.md`
  - `docs/AI_REGRESSION_LESSONS.md`（代課/評量族群：R39/R46/R48/R52）
  - `docs/CHAT_BUG_SYSTEM.md` §3.6–3.7（in-app bug SOP）

## 2. 目標與業務背景
- 痛點（非技術語言）：
  - 介面視覺語言不夠一致，使用者主觀感受「AI 模板感重」，影響專業信任。
  - 老師在填評量時不易快速掌握學生上一堂進度，遇到代課交接時更明顯。
- 業務價值：
  - 提升主任/老師對系統「專業、可依賴」的感受，降低 UI 摩擦。
  - 降低評量填寫前的查找成本，加速教學交接，降低內容斷層。
- KPI（可量化）：
  - KPI-1：高曝光頁（首批 4 頁）設計規範符合率達 95%（token + 排版 + 互動檢核表）。
  - KPI-2：評量表開啟後 10 秒內可看到上一堂摘要比例 >= 99%（有前一堂資料者）。
  - KPI-3：老師回饋「需另開歷史查上一堂」事件數，4 週內下降 60%。

## 3. 範圍（In Scope / Out of Scope）
### In Scope
1. 建立 UI 治理執行框架（以 `RULE_DESIGN_SYSTEM` 為唯一真相來源）。
2. 首批高曝光頁逐頁治理（不大爆改，走小 PR）。
3. 在學習評量表新增「上一堂摘要」唯讀區塊，包含授課老師（代課標示）與上一堂關鍵欄位。
4. 補齊對應測試、驗收清單、上線監控與回滾策略。

### Out of Scope
1. 一次性全站大改（big bang re-design）。
2. 新增全新設計系統平台/套件（本期維持現有 token 架構）。
3. 改動評量審核流程或計費邏輯。
4. 改動 production 環境手動部署流程（維持 PR merge 後 `deploy.yml`）。

## 4. RACI
| 角色 | 代表 | R/A/C/I |
|---|---|---|
| AI Agent（實作） | `[FEATURE]` | R |
| AI Agent（測試） | `[TEST]` | R |
| AI Agent（審查） | `[REVIEW]` | R |
| AI Agent（文件） | `[DOCS]` | R |
| AI Agent（部署） | `[OPS]` | R |
| 人類（CEO） | 使用者 | I |

## 4b. Dependencies
| 類型 | 說明 | 狀態 |
|---|---|---|
| 前置 Ticket / PR | `#684` Epic、`#685` 功能單已建立 | 已完成 |
| 外部服務 / API | 無第三方依賴，沿用既有後端 API 與資料表 | 可用 |
| 環境 / 資料前提 | 需存在同課程前一筆評量資料才能顯示摘要 | 已存在 |
| 文件前提 | `RULE_DESIGN_SYSTEM` 為治理規範來源 | 已存在 |

## 5. User Stories + AC
- US-1（主任/老師）  
  As a teacher/director, I want to see previous lesson context in learning form, so that I can continue teaching without searching history.
  - AC-1：開啟評量表時，若該課程有前一堂資料，顯示上一堂摘要卡。
  - AC-2：摘要包含上一堂日期、授課老師、授課進度、作業範圍、作業完成狀態。
  - AC-3：若上一堂是代課，摘要清楚顯示代課標示，不誤導為正班老師。

- US-2（super_admin）  
  As a super admin, I want UI modernization to be incremental and reversible, so that quality improves without deployment shock.
  - AC-1：每頁改動獨立 PR，單頁可回滾，不影響其他頁。
  - AC-2：每頁改動前後皆可對照設計檢核表，結果可審計。

- US-3（老師）  
  As a teacher, I want loading/empty/error states to be explicit, so that I know what to do when previous lesson is unavailable.
  - AC-1：無前一堂資料時有明確空狀態文案。
  - AC-2：載入中顯示 skeleton 或 loading，不跳版。
  - AC-3：資料讀取失敗顯示可重試提示。

## 5b. UI/UX 精緻化需求
| 面向 | LearningRecordsPage（#685） | 高曝光頁治理（#684 首批） |
|---|---|---|
| 版面層次 | 在評量表資訊區加入「上一堂摘要」卡，標題/內文/輔助層級依 token | 標題階層、卡片密度、欄位間距統一 |
| 色彩一致性 | 使用 `--ds-*` token；禁止臨時硬寫色碼 | 全頁按 design token 對齊，移除風格漂移 |
| 互動回饋 | 載入態、錯誤態、重試按鈕、儲存回饋一致 | CTA/hover/active 規範一致 |
| 空狀態設計 | 無上一堂時顯示「尚無前一堂資料」+ 說明 | 各頁空狀態統一圖示/文案/CTA |
| 載入狀態 | 摘要卡先 skeleton，完成後替換資料 | 面板/列表載入減少 layout shift |
| 防呆設計 | 摘要為唯讀，避免誤編；欄位超長截斷+可展開 | 危險操作維持二次確認 |
| 響應式 | 行動裝置摘要卡可單欄顯示，不溢出 | 主要操作區在小寬度下可用 |
| 無障礙 | 對比 >= 4.5:1、鍵盤可達、aria 標示 | 治理頁面逐頁符合基本可及性 |

## 6. 功能需求（FR）
- FR-001：系統應在評量表回傳或載入流程中提供「同 `student_course_id` 上一堂」摘要資料。
- FR-002：系統應在上一堂為代課情境下，顯示有效授課老師資訊與代課標示。
- FR-003：系統應在無上一堂資料時回傳可判斷空態的訊號，前端顯示空狀態。
- FR-004：系統應保留既有評量填寫流程與權限，不因摘要功能改變授權邏輯。
- FR-005：UI 治理應採「每頁一 PR」模式，首批至少覆蓋 4 個高曝光頁。
- FR-006：每個治理 PR 應附設計檢核結果（token、層級、空/載/錯、可及性）。

## 7. 非功能需求（NFR）
- NFR-001（效能）：評量摘要資料載入不使表單首屏延遲超過 500ms（同網段一般負載）。
- NFR-002（穩定）：摘要失敗不得阻斷評量表開啟與儲存主流程。
- NFR-003（可維護）：新增 UI 樣式應優先復用既有 token，不新增分散色碼。
- NFR-004（可回滾）：任一頁治理可獨立 `git revert` 回退，不需回退整包。

## 8. 技術方向（禁止 code）
- Workstream A（#684）：
  - 採 token-first + incremental rollout。
  - 按頁面群分批治理，優先高曝光與高使用頻率頁面。
  - 以「設計檢核表」作為每頁 merge gate。
- Workstream B（#685）：
  - 評量表增加上一堂摘要資料來源與顯示區塊。
  - 摘要資料以同課程前一堂為界，保留代課可視性。
  - 失敗降級為可用空態/錯態，不阻斷主流程。

## 8b. Decision Log
| 日期 | 決策 | 替代方案 | 選擇理由 |
|---|---|---|---|
| 2026-06-06 | UI 走漸進式逐頁治理 | 一次性全站改版 | 風險低、可回滾、便於審查 |
| 2026-06-06 | 摘要只顯示同課程上一堂 | 顯示跨課程最近一堂 | 同課程語意明確，避免混淆 |
| 2026-06-06 | 摘要優先支援代課可視性 | 只顯示正班老師 | 與既有代課規則一致，降低復發 |
| 2026-06-06 | docs 提醒以既有治理文件承接 | 另建新 SOP 長文 | 避免多份 SOP 漂移，維持單一真相 |

## 9. 資安與存取控制（STRIDE）
- S（偽冒）：沿用既有 auth middleware，摘要僅回傳授權可見資料。
- T（竄改）：上一堂摘要預設唯讀，不提供直接編輯入口。
- R（否認）：維持既有評量操作與狀態記錄。
- I（洩漏）：不新增跨分校查詢；遵守 `require_campus` 與 role 邊界。
- D（阻斷）：摘要查詢失敗時不影響評量主流程（降級顯示）。
- E（提權）：不新增繞過角色的 API 路徑。

## 10. QA 驗收
- Happy Path
  - HP-1：有上一堂資料時，摘要正確顯示。
  - HP-2：上一堂為代課時，授課老師與標示正確。
  - HP-3：高曝光頁治理後，檢核表全通過。
- Edge Cases
  - EC-1：無上一堂資料時顯示空態而非報錯。
  - EC-2：上一堂欄位過長時版面不破壞。
  - EC-3：摘要 API 延遲時不阻塞主編輯流程。
- Error Cases
  - ER-1：摘要資料抓取失敗時顯示可重試提示。
  - ER-2：任一治理頁回退後其餘頁不受影響。
- UI/UX 檢核
  - 空狀態、載入、錯誤回饋完整
  - 色彩/間距/字級符合 token
  - 行動裝置可用、對比度達標

## 11. 上線與維運
- 部署步驟：
  1. feature branch 小 PR（每頁或每功能一 PR）
  2. CI 綠
  3. PR merge 觸發 `deploy.yml`
  4. 驗 `GET /api/v1/health`，前端改動再驗 `version.json`
- Feature Flag 策略：
  - #685 可採「UI 區塊顯示旗標」先內部開啟，再全量。
  - #684 逐頁治理本身即為低風險分段發布。
- Observability
| 監控項目 | 指標 / 關鍵字 | 告警閾值 | 負責 Agent |
|---|---|---|---|
| 評量摘要載入失敗率 | learning-summary error rate | > 2% / 1h | `[OPS]` |
| 前端關鍵頁 JS 錯誤 | LearningRecordsPage runtime errors | 持續 10 分鐘 | `[OPS]` |
| 發布後健康檢查 | `/api/v1/health` | 非 `ok` 立即回滾 | `[OPS]` |
- 回滾：
  - UI 問題：回退該頁 PR
  - 摘要功能異常：關閉旗標或回退 #685 PR

## 12. 里程碑與優先級
- Priority：
  - P1：#685 評量上一堂摘要（直接影響教學交接）
  - P2：#684 首批 4 頁設計治理
  - P3：其餘頁面治理
- Milestones：
  - M1：#685 上線（含測試）
  - M2：#684 首批 4 頁治理完成
  - M3：其餘頁面按 touched-by-work 漸進完成

## 13. 風險 / 假設 / 開放問題（含業界/開源研究）
| 風險 | 等級 | 業界/開源參考 | 本專案採行方式 |
|---|---|---|---|
| 一次改太多 UI 導致回歸風險 | 高 | Martin Fowler token-based architecture；GitHub Primer token migration（漸進 + fallback） | 每頁小 PR + 可回滾，不做 big-bang |
| token 遷移變成長期雙軌技術債 | 中 | DesignSystems.one token bridge（先定退場門檻） | 為 #684 設覆蓋率門檻與淘汰條件 |
| 摘要功能造成主流程阻塞 | 高 | 教育場景 continuity pattern（摘要是輔助，不可阻斷教學操作） | 摘要失敗降級，不阻塞評量主流程 |
| 代課資訊再次顯示錯誤（復發） | 高 | 本專案既有 R39/R46/R48/R52 經驗 | 摘要設計直接對齊有效授課者語意並補 regression 測試 |

- 假設：
  - A1：同課程前一堂資料可由現有資料模型穩定取得。  
    - 偵測：若摘要命中率低於預期，回報並切換為「可重試 + 提示查歷史」。
  - A2：逐頁治理不需要新增設計 infra。  
    - 偵測：若同類樣式重複修補超過閾值，升級為 token 層優先整改。

- 開放問題：
  - OQ-1 `[AI-RESOLVABLE]`：#684 首批 4 頁最終排序是否依使用頻率再微調？
  - OQ-2 `[BLOCKED: CEO 決策]`：#685 上一堂摘要欄位是否要包含「上次週考分數」預設顯示？
  - OQ-3 `[BLOCKED: CEO 決策]`：#685 是否僅顯示已核准評量，或包含待審資料？

## 14. Definition of Done（AI 可自動驗證）
- [ ] #685 功能完成：驗證方式：對應 API/前端測試通過，顯示上一堂摘要（含代課情境）。
- [ ] #685 不阻塞主流程：驗證方式：模擬摘要失敗時仍可開啟與儲存評量。
- [ ] #684 首批頁面治理：驗證方式：每頁檢核表通過且 CI 綠。
- [ ] 資安/權限不退化：驗證方式：`[REVIEW]` 對 role + campus 邊界檢查無 HIGH 風險。
- [ ] 文件同步：驗證方式：`docs/CHANGELOG.md` 更新且 issue/PR 對應完整。
- [ ] 上線驗收：驗證方式：merge 後 `deploy.yml` 成功 + `/api/v1/health` 為 `ok`。

---

## 執行 Todos（9 類）
1. **後端 API / 資料** `[FEATURE]`：定義並實作上一堂摘要資料供應（同課程前一堂）。
2. **前端 UI 功能** `[FEATURE]`：LearningRecordsPage 新增上一堂摘要卡與資料綁定。
3. **UI/UX 精緻化** `[FEATURE]`：#684 首批 4 頁按設計規範逐頁治理。
4. **測試設計與自動執行** `[TEST]`：新增/更新評量摘要與代課情境 regression。
5. **自動化 QA 驗收** `[TEST]`：跑 HP/Edge/Error + UI 檢核清單。
6. **資安靜態審查** `[REVIEW]`：STRIDE + role/campus 邊界確認。
7. **Code Review** `[REVIEW]`：逐條 FR 對照，Critical 清空。
8. **文件更新** `[DOCS]`：`CHANGELOG`（必要時補 `AI_REGRESSION`）。
9. **部署與 health check** `[OPS]`：CI 綠後 merge，驗 deploy 與 health。

---

## 需 CEO 批准的決策（才能進 Phase 2 [DEV]）
1. 是否同意以本 PRD 作為 #684 + #685 的執行基線？
2. #685 摘要欄位是否預設包含「上次週考分數」？
3. #685 資料來源是否限定「已核准評量」？
4. #684 首批 4 頁排序是否採：`DirectorDashboard` → `TeacherHomePage` → `LearningRecordsPage` → `SmartCalendar`？
