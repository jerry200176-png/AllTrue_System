# PRD — 主任/老師高頻開啟計畫：每日開工清單 + 流程 SLA 提醒（MVP）

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | Adoption Daily Activation + Workflow SLA |
| 版本 | v1.0 |
| 狀態 | Draft (可進 DEV) |
| 目標角色 | director / teacher / admin / super_admin |
| 建立日期 | 2026-05-09 |

---

## 2. 目標與業務背景

- 現況痛點：主任與老師沒有「每天非開不可」的入口，導致流程常回到紙本或 Excel，待辦容易遺漏。
- 業務價值：把系統變成每日第一個工作入口，並用可追蹤流程降低遺漏、追問與人工補救成本。
- KPI（上線後 30 天）：
  - 老師 7 日開啟率 >= 75%（目前基準值由既有 weekly metrics 讀取）。
  - 主任 7 日開啟率 >= 85%。
  - 請假/調課/待審評量 24 小時內完結率 >= 90%。
  - 逾期流程（SLA breach）比例下降 40%。

---

## 3. 範圍

### In Scope
- 老師/主任首頁「每日開工清單」（Top 3 優先任務 + 一鍵深連結）。
- 流程 SLA 分級（即將逾期 / 已逾期）與升級提醒機制。
- 待辦卡片加入「阻塞影響」提示（例如：誰被卡住、影響哪個流程）。
- 主任視角增加「今日應處理數、已完成數、逾期數」指標卡。
- 流程完成後即時回寫 KPI（前端事件 + 後端聚合）。

### Out of Scope
- 新增外部推播通道（例如 SMS/Email）與計費。
- 全新通知中心重構。
- 新的排課與財務核心演算法改寫。

---

## 4. RACI

| 角色 | 代表 | R/A/C/I |
|---|---|---|
| AI Agent（實作） | `[FEATURE]` | R |
| AI Agent（測試） | `[TEST]` | R |
| AI Agent（審查） | `[REVIEW]` | R |
| AI Agent（文件） | `[DOCS]` | R |
| AI Agent（部署） | `[OPS]` | R |
| 人類 | CEO / PM / 校區主任 | I |

---

## 4b. Dependencies

| 類型 | 說明 | 狀態 |
|---|---|---|
| 前置 PR / Ticket | Adoption v1 API（task-tracker / activity-log / weekly-metrics / events）已上線 | 已完成 |
| 外部服務 / API | 本次無外部依賴 | 本次無外部依賴 |
| 環境 / 資料前提 | 需新增 SLA 欄位或衍生計算（due_at、breach_level、blocked_count） | 待完成 |

---

## 5. User Stories + AC

1) As a 老師, I want 一登入就看到今天最重要的 3 件事, so that 我不用先翻多個頁面找工作。
AC:
- 首頁 3 張卡片依優先度排序（逾期 > 今日到期 > 一般）。
- 每張卡片可一鍵跳到正確分頁與對應資料列。

2) As a 主任, I want 知道哪些流程快逾期/已逾期, so that 我能及早介入，避免卡住整體營運。
AC:
- 主任首頁顯示即將逾期（<= 4 小時）與已逾期項目數。
- 每筆逾期項目可看到責任角色與受影響對象。

3) As a 主任, I want 每日看到完成率與逾期率, so that 我可以判斷系統是否真的被使用。
AC:
- 顯示今日應處理、已完成、逾期三個指標。
- 完成任務後 5 秒內刷新統計，不需整頁重整。

4) As a 老師/主任, I want 卡片操作有即時回饋, so that 我知道是否真的處理成功。
AC:
- 卡片點擊後顯示 loading 與成功/失敗提示。
- 成功後卡片自動降序或移除，避免重複操作。

---

## 5b. UI/UX 精緻化

| 面向 | 規格 |
|---|---|
| 版面層次 | 首屏固定「今日 3 件事」區塊，置於首頁主操作區第一屏內；主任加「SLA 健康卡」並列 |
| 色彩一致性 | 即將逾期使用 warning token；已逾期使用 danger token；已完成使用 success token |
| 互動回饋 | 卡片點擊即鎖定避免連點；右上 toast 4 秒；失敗時附下一步建議 |
| 空狀態設計 | 無待辦時顯示「今日流程已清空」插圖與「查看本週目標」CTA |
| 載入狀態 | 待辦卡與指標卡使用 skeleton（最少 2 行）避免 layout shift |
| 防呆設計 | 高風險流程卡點擊前顯示影響摘要（影響對象/時限）；跨分校資料禁止深連結 |
| 響應式 | 桌機 2 欄（任務 + 指標）；手機單欄堆疊，優先顯示任務卡 |
| 無障礙 | 觸控目標 >= 44px；顏色對比 >= 4.5:1；卡片/按鈕提供 aria-label |

---

## 6. 功能需求（FR）

- FR-001：系統應回傳老師/主任每日 Top 3 任務，並帶 priority、due_at、deep_link、blocked_count。
- FR-002：系統應對每筆任務計算 SLA 狀態（normal / warning / breached）。
- FR-003：主任首頁應顯示今日流程摘要（due_total、done_total、breached_total）。
- FR-004：任務卡點擊後應記錄事件（dashboard_opened、todo_card_clicked、flow_submitted、flow_completed）。
- FR-005：任務完成後應在 5 秒內反映到待辦與指標（前端刷新或增量更新）。
- FR-006：跨校區或無權限深連結應拒絕並回傳可讀錯誤原因。
- FR-007：逾期任務需顯示責任角色與受影響對象摘要。

---

## 7. 非功能需求（NFR）

- NFR-001：待辦 API P95 < 400ms。
- NFR-002：SLA 指標 API P95 < 500ms。
- NFR-003：首頁首屏任務區塊可互動時間 < 2.0s（桌機）/ < 2.8s（手機）。
- NFR-004：事件追蹤 API 失敗時不阻擋主流程（非阻塞寫入）。
- NFR-005：若任務聚合失敗，前端降級顯示最後一次可用快照與重試入口。

---

## 8. 技術方向（無程式碼）

- 前端：沿用現有 teacher/director dashboard 區塊，擴充任務卡 SLA 標記與今日摘要卡。
- 後端：在既有 adoption task-tracker/weekly-metrics 基礎上加入 SLA 衍生欄位與責任摘要。
- 資料層：優先採衍生計算（query-time）實作；若壓力過高再加入快取表。
- 追蹤層：沿用 adoption events，新增完成事件類型，維持非阻塞寫入策略。
- 權限層：全部沿用 role + campus isolation；deep link 需二次驗權。

---

## 8b. Decision Log

| 日期 | 決策 | 替代方案 | 選擇理由 |
|---|---|---|---|
| 2026-05-09 | 先做「Top 3 任務」而非完整待辦清單 | 顯示完整清單 | 降低首屏認知負擔，先提升開啟與啟動速度 |
| 2026-05-09 | SLA 採三段分級（normal/warning/breached） | 只做逾期二元標記 | 三段分級可提前介入，避免任務到逾期才處理 |
| 2026-05-09 | 事件追蹤維持非阻塞 | 事件寫入失敗即阻擋操作 | 避免追蹤系統問題影響一線業務操作 |

---

## 9. 資安與存取控制

- 角色：teacher 看個人任務；director/admin/super_admin 可看分校流程摘要。
- PII：任務卡禁止顯示非必要個資；預設顯示姓名縮寫或角色摘要。
- STRIDE 快評：
  - S：深連結操作前再次驗證 token 與角色。
  - T：任務狀態更新使用事件 + server-side 驗證，避免前端偽造完成。
  - R：所有點擊與完成事件保留時間與操作者資訊。
  - I：跨校區任務完全隔離，不回傳他校摘要。
  - D：事件與聚合 API 設 rate limit，避免濫刷。
  - E：禁止透過 deep link 越權存取非授權頁面。

---

## 10. QA 驗收

### Happy Path
- 老師登入後 2 秒內看到今日 Top 3 任務並能一鍵前往處理。
- 主任可看到即將逾期與逾期任務數，且可導向對應處理頁。
- 任務完成後首頁統計同步更新。

### Edge / Error
- 任務已被他人處理：點擊後提示「已更新」並刷新清單。
- API 暫時失敗：顯示快照與重試，不白屏。
- 深連結權限不足：回 403 並顯示可讀提示，不進入錯頁。

### UI/UX 驗收清單
- [ ] 空狀態有圖示 + 說明 + CTA
- [ ] 非同步區塊皆有 loading/skeleton
- [ ] 成功/失敗回饋可辨識
- [ ] 高風險操作有二次提示
- [ ] 手機無水平 overflow，觸控目標 >= 44px
- [ ] 色彩對比 >= 4.5:1，鍵盤可操作

---

## 11. 上線與維運

- 部署：PR merge 後由 `deploy.yml` 自動部署；若僅 docs 變更，應自動跳過 production deploy。
- Feature Flag：
  - `adoption_daily_top3_v1`（每日開工清單）
  - `workflow_sla_warning_v1`（SLA 分級提示）
  - 上線順序：大安/木柵內部觀察 3 天 -> 全分校。
- Observability：

| 監控項目 | 指標 / log 關鍵字 | 告警閾值 | 負責 Agent |
|---|---|---|---|
| 首頁待辦載入失敗率 | `adoption_task_tracker_failed` | > 3% / 15min | `[OPS]` |
| SLA 逾期暴增 | `workflow_sla_breached_count` | 日增 > 30% | `[OPS]` |
| 深連結權限拒絕率 | `adoption_deeplink_forbidden` | > 5% / 15min | `[OPS]` |
| 事件寫入失敗率 | `adoption_event_write_failed` | > 2% / 15min | `[OPS]` |

- 回滾：`git revert <feature commit>` 回退前後端變更；若無 migration 則無 DB rollback；預估 15 分鐘內恢復。

---

## 12. 里程碑與優先級

- P0（本週）：FR-001 ~ FR-004（Top 3、SLA 分級、今日摘要、事件追蹤）。
- P1（次週）：FR-005 ~ FR-007（即時回寫、權限防呆、阻塞影響提示）。
- P2（後續）：跨頁任務推薦與個人化提醒時段。

---

## 13. 風險 / 假設 / 開放問題（含業界與開源參考）

| 風險 | 等級 | 業界/開源參考 | 本專案採行方式 |
|---|---|---|---|
| 通知太多反而疲乏，造成忽略待辦 | 高 | GitHub Notifications triage（先處理最阻塞項） | 首屏只放 Top 3 + 影響對象，避免資訊爆量 |
| 指標追蹤被質疑監控個人 | 中 | Microsoft Viva Insights（team-level insight + privacy guard） | 以分校/角色聚合為主，不展示個人敏感行為軌跡 |
| 連續失敗讓使用者放棄回來使用 | 中 | Habo / StreakBase（低摩擦、進度導向） | 任務完成強調「今日進度」而非懲罰式 streak 歸零 |
| 逾期定義不準導致錯誤告警 | 中 | GitHub workflow inbox filter（明確分類規則） | SLA 規則寫死為可測試條件並提供例外白名單 |

假設：
- 假設 A：現有 task-tracker 可補足 due_at 與責任角色；若不成立，`[FEATURE]` 自動降級為僅顯示「今日應處理數」不做 Top 3。
- 假設 B：首頁資料量可在 P95 500ms 內完成；若不成立，`[OPS]` 啟用快取並在 dashboard 顯示「剛剛更新」。

開放問題：
- `[AI-RESOLVABLE]` warning 門檻要用 4 小時或 8 小時，需以歷史完成時間分佈自動估算。
- `[AI-RESOLVABLE]` Top 3 是否加入「可快速完成」權重（處理時間預估 <= 3 分鐘）。

---

## 14. Definition of Done

- [ ] 每日開工清單可用：驗證方式：老師/主任登入後看到 Top 3 任務並可深連結。
- [ ] SLA 分級正確：驗證方式：測試資料覆蓋 normal/warning/breached 三類均正確分類。
- [ ] 流程摘要可即時更新：驗證方式：完成任務後 5 秒內首頁指標變更。
- [ ] 權限與分校隔離正確：驗證方式：跨校與低權限情境回傳 403。
- [ ] 追蹤事件不阻塞主流程：驗證方式：模擬事件寫入失敗，主要操作仍成功。
- [ ] 前端 build 成功：驗證方式：`cd frontend && npm run build` 成功。
- [ ] 文件更新完成：驗證方式：`docs/CHANGELOG.md` 出現對應條目。
- [ ] 健康檢查通過：驗證方式：`curl -sk https://daan.lifenet.com.tw/api/v1/health` 回傳 `{"status":"ok"}`。

---

## Todos（9 類）

1. `[FEATURE]` 後端 API：task-tracker/metrics 加入 SLA 欄位與 blocked 影響摘要。
2. `[FEATURE]` 前端 UI：老師/主任首頁新增 Top 3 任務區與今日流程摘要卡。
3. `[FEATURE]` UI/UX 精緻化：SLA 色階、空狀態、錯誤提示、手機版可用性。
4. `[TEST]` 測試：新增 SLA 分類與 deep-link 權限測試。
5. `[TEST]` 自動 QA：跑 Happy/Edge/Error 驗收清單並出報告。
6. `[REVIEW]` 資安靜態審查：STRIDE 與 campus isolation 檢查。
7. `[REVIEW]` Code Review：逐條對照 FR/NFR 與性能門檻。
8. `[DOCS]` 文件更新：`docs/CHANGELOG.md`、`docs/SYSTEM_TECH_GUIDE.md` adoption 章節。
9. `[OPS]` 部署與 health check：監控 deploy.yml、驗證 `/api/v1/health` 與關鍵頁首屏可用。
