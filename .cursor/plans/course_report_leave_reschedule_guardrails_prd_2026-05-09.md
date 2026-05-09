# PRD — 課程回報 / 請假 / 調課 / 取消課程防呆精緻化（MVP）

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 課務變更防呆與精緻化 MVP |
| 版本 | v1.0 |
| 狀態 | Draft (可進 DEV) |
| 目標角色 | director / teacher / admin / super_admin |
| 建立日期 | 2026-05-09 |

---

## 2. 目標與業務背景

- 現況痛點：請假、調課、取消課程流程容易誤操作，變更影響（堂數、通知、代課、教室）不透明。
- 業務價值：降低錯單與補救成本，提升主任與老師操作信心，減少口頭追問與客服負擔。
- KPI（30 天觀察）：
  - 課務變更後反悔/人工更正單比例下降 40%
  - 請假/調課提交後 24 小時內完結率 >= 90%
  - 因誤操作造成的「同日重複修正」事件下降 50%

---

## 3. 範圍

### In Scope
- 送出前「影響預覽」(Impact Preview)：展示會影響的堂次、堂數、通知對象。
- 高風險操作二次確認（取消課程、批次變更）。
- 提交成功後短時間 Undo（撤銷）能力。
- 前端統一錯誤文案（原因 + 下一步建議）。
- 課務變更狀態流明確化（pending/approved/rejected/cancelled/executed）。
- 異動時間軸（audit timeline）基礎版。

### Out of Scope
- 新的計費規則與合約重算引擎。
- 全新排課演算法重寫。
- 跨產品通知中心重構。

---

## 4. RACI

| 角色 | 代表 | R/A/C/I |
|---|---|---|
| AI Agent（實作） | `[FEATURE]` | R |
| AI Agent（測試） | `[TEST]` | R |
| AI Agent（審查） | `[REVIEW]` | R |
| AI Agent（文件） | `[DOCS]` | R |
| AI Agent（部署） | `[OPS]` | R |
| 人類 | CEO / PM / 班主任 | I |

---

## 4b. Dependencies

| 類型 | 說明 | 狀態 |
|---|---|---|
| 前置 PR / Ticket | 現有課務 API（schedules / class-sessions）可用 | 已完成 |
| 外部服務 / API | 無第三方硬依賴 | 本次無外部依賴 |
| 環境 / 資料前提 | 需新增課務異動操作記錄欄位或表（供 timeline/undo） | 待完成 |

---

## 5. User Stories + AC

1) As a 主任, I want 在送出調課前看到完整影響, so that 我不會誤改到錯誤堂次。  
AC:
- 送出前顯示 affected sessions 數量、日期、老師、教室。
- 若有衝突，禁止送出並顯示可行下一步。

2) As a 老師, I want 提交請假後可短時間撤銷, so that 我可以補救誤觸。  
AC:
- 成功 toast 顯示「撤銷」按鈕，30 秒內有效。
- 逾時後按鈕消失且狀態不可直接回退。

3) As a 主任, I want 取消課程前有後果提示, so that 我知道堂數與通知影響。  
AC:
- 二次確認窗顯示「影響堂數 / 通知對象 / 是否可恢復」。
- 未勾選理解後果不得確認。

---

## 5b. UI/UX 精緻化

| 面向 | 規格 |
|---|---|
| 版面層次 | 課務操作統一進入 Action Drawer，分三段：操作內容、影響預覽、確認送出 |
| 色彩一致性 | 危險操作採用 danger token；可恢復操作採 warning token |
| 互動回饋 | 送出按鈕 loading；成功/失敗 toast 固定右上 4 秒 |
| 空狀態設計 | 無可影響堂次時，顯示圖示 + 「目前沒有可變更堂次」+ 返回課表 CTA |
| 載入狀態 | 影響預覽區塊採 skeleton，避免 layout shift |
| 防呆設計 | 必填原因、衝突即時提示、危險操作二次確認、Undo |
| 響應式 | 桌機 Drawer 寬版；手機 full-screen sheet |
| 無障礙 | Tab 可操作；對比 >= 4.5:1；觸控目標 >= 44px；aria-label 完整 |

---

## 6. 功能需求（FR）

- FR-001：課務操作提交前，系統應返回影響預覽資料（sessions/notifications/session_deduction impact）。
- FR-002：衝突（老師/教室/時段）存在時，系統應阻止提交並附帶 machine-readable reason code。
- FR-003：取消課程操作應要求二次確認，且前端需展示影響摘要。
- FR-004：成功提交後系統應提供可撤銷 token（30 秒有效）。
- FR-005：撤銷成功後，原操作與撤銷操作都應記錄於 audit timeline。
- FR-006：所有課務異動應有統一狀態與狀態轉換限制。
- FR-007：課務詳情頁應可查詢 timeline（誰/何時/做了什麼/理由）。

---

## 7. 非功能需求（NFR）

- NFR-001：影響預覽 API P95 < 500ms。
- NFR-002：操作送出 API P95 < 700ms。
- NFR-003：Undo API P95 < 500ms。
- NFR-004：若預覽 API 失敗，前端降級為阻止送出 + 顯示重試；禁止盲送。
- NFR-005：所有失敗需回傳可觀測錯誤碼（便於 log/告警）。

---

## 8. 技術方向（無程式碼）

- 前端：在既有課務頁（CourseManagement/SmartCalendar）新增共用 Action Drawer 與 Impact Preview 區塊。
- 後端：在既有課務變更 API 前置一層 Preview/Validate，提交時要求帶 preview nonce 或 version。
- 資料層：新增或擴充課務異動記錄（operation log）以支援 timeline 與 undo 窗口。
- 權限：沿用 role + campus isolation，所有預覽與提交都必須同校區授權。
- 錯誤模型：統一 reason codes（time_conflict, room_conflict, permission_denied, stale_preview 等）。

---

## 8b. Decision Log

| 日期 | 決策 | 替代方案 | 選擇理由 |
|---|---|---|---|
| 2026-05-09 | 採「先預覽再提交」雙階段 | 直接提交後再報錯 | 先阻止錯誤最省成本，體驗更可控 |
| 2026-05-09 | Undo 採短窗口（30 秒） | 長窗口（5 分鐘） | 降低狀態複雜度與並發衝突 |
| 2026-05-09 | Timeline 先做單頁可查 | 全系統事件中心 | MVP 優先解決課務誤操作追蹤 |

---

## 9. 資安與存取控制

- 角色：teacher 可提單；director/admin/super_admin 可審核/取消高風險操作（依現有權限矩陣）。
- PII：理由欄位禁止輸入敏感個資模板（前端提示 + 後端最小化紀錄）。
- STRIDE 快評：
  - S：操作人與 token 綁定，禁止代送。
  - T：提交需帶 preview version，防止過時資料覆寫。
  - R：所有操作寫入不可否認 audit log。
  - I：timeline 僅授權角色可見。
  - D：預覽 API 設 rate limit，避免濫用。
  - E：跨校區操作全部拒絕並告警。

---

## 10. QA 驗收

### Happy Path
- 請假/調課/取消均可看到預覽並成功提交。
- 提交後 30 秒內可撤銷且資料恢復一致。

### Edge / Error
- 預覽後資料被他人修改：提交應回 stale_preview。
- 老師撞堂、教室撞堂、權限不足皆可阻止提交且訊息可懂。
- Undo 逾時應拒絕並提示重新發起流程。

### UI/UX 驗收清單
- [ ] 空狀態有圖示+說明+CTA
- [ ] 所有非同步操作有 loading
- [ ] 成功/失敗回饋清楚
- [ ] 危險操作有二次確認
- [ ] 手機觸控可用，無水平 overflow

---

## 11. 上線與維運

- 部署：PR merge 後由 `deploy.yml` 自動部署（含前端 build）。
- Feature Flag：
  - `guardrails_preview_v1`（preview+validate）
  - `guardrails_undo_v1`（undo）
  - 先 director 內部校區 -> 全校區。
- Observability：

| 監控項目 | 指標 / log 關鍵字 | 告警閾值 | 負責 Agent |
|---|---|---|---|
| 預覽失敗率 | `schedule_preview_failed` | > 5%/15min | `[OPS]` |
| 提交衝突率 | `schedule_submit_conflict` | > 15%/15min | `[OPS]` |
| Undo 失敗率 | `schedule_undo_failed` | > 3%/15min | `[OPS]` |

- 回滾：`git revert` 該 PR；若含 migration 則補 rollback migration；預估 15-30 分鐘。

---

## 12. 里程碑與優先級

- P0（本週）：FR-001~FR-004（預覽、阻擋、二次確認、undo）。
- P1（次週）：FR-005~FR-007（timeline、狀態完整化）。
- P2（後續）：推薦可行時段、批次精緻化。

---

## 13. 風險 / 假設 / 開放問題（含業界與開源參考）

| 風險 | 等級 | 業界/開源參考 | 本專案採行方式 |
|---|---|---|---|
| 取消/調課造成費用漏算 | 高 | FitGap（cancellation as financial event） | 先做 impact preview 顯示計費影響，再允許提交 |
| 假預覽（提交時資料已變） | 高 | Enterprise scheduling best practice（preflight + revalidate） | 提交強制版本檢查，失效即重預覽 |
| 多層審批造成等待 | 中 | ERPNext Workflow/Leave Approval | MVP 採單層審批，保留多層擴充 |
| 排程衝突提示不直觀 | 中 | Cal.com scheduling UX | 錯誤碼映射成「可行下一步」文案 |

假設：
- 假設 A：目前 API 已可返回必要排程資料；若不成立，`[FEATURE]` 以缺欄位檢查自動 fallback 為「僅允許查詢、禁止提交」。
- 假設 B：director 角色可覆蓋老師提單；若不成立，`[REVIEW]` 會在權限測試中標記阻塞並回報 `[BLOCKED: 權限矩陣未定義]`。

開放問題：
- `[AI-RESOLVABLE]` Undo 窗口是否 30 秒或 60 秒（可由歷史操作資料估算最佳值）。
- `[AI-RESOLVABLE]` Timeline 是否同時進入通知中心（先評估噪音比例）。

---

## 14. Definition of Done

- [ ] 預覽與提交流程可用：驗證方式：前端 E2E 情境通過且衝突情境被阻止。
- [ ] Undo 可在時限內回復：驗證方式：自動測試驗證 30 秒內成功、逾時失敗。
- [ ] 狀態與 timeline 正確：驗證方式：API 測試確認每次操作均有記錄。
- [ ] 權限與校區隔離無誤：驗證方式：跨校區/低權限測試回 403。
- [ ] 前端 build 通過：驗證方式：`cd frontend && npm run build` 成功。
- [ ] CHANGELOG 更新：驗證方式：diff 含對應條目。
- [ ] 部署健康檢查：驗證方式：`curl -sk https://daan.lifenet.com.tw/api/v1/health` 回 `{"status":"ok"}`。

---

## Todos（9 類）

1. `[FEATURE]` 後端 API：新增 preview/validate 與 submit version-check。
2. `[FEATURE]` 前端 UI：Action Drawer + 影響預覽 + 二次確認。
3. `[FEATURE]` UI/UX 精緻化：錯誤文案、空狀態、loading、undo entry。
4. `[TEST]` 測試：新增 API/整合測試（衝突、過時預覽、undo）。
5. `[TEST]` 自動 QA：跑 Happy/Edge/Error 驗收清單。
6. `[REVIEW]` 資安靜態審查：STRIDE + 權限/校區檢查。
7. `[REVIEW]` Code Review：逐條對照 FR 與 NFR。
8. `[DOCS]` 文件更新：`docs/CHANGELOG.md` + 必要 runbook 補註。
9. `[OPS]` 部署與 health check：merge 後監控 deploy.yml + health/version 驗證。
