# [BUG] Parent Feedback Unread State + Findability

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P2 |
| 根因類型 | 邏輯錯誤 + UX 可發現性不足 |
| 根因摘要 | `LearningRecordFeedbackController::markRead()` 用 Eloquent `save()` 更新已讀欄位時會同步更新 `updated_at`，而未讀判斷又以 `last_read_at < updated_at` 為準，導致讀完刷新後可能又被判為未讀。 |
| 錯誤行為 | 老師/主任看過家長回饋後，畫面短暫變已讀，但刷新或重新載入後又顯示未讀；且評量列表缺少「只看家長回饋」入口，難定位是哪筆評量有回饋。 |
| 預期行為 | staff 讀取回饋後，刷新仍維持已讀；使用者可一鍵只看有家長回饋或未讀回饋的評量。 |
| 影響範圍 | 老師、主任、super_admin；`LearningRecordsPage`、`LearningRecordFeedbackController`、`LearningRecordController::decorateRecords()` 回傳欄位。 |
| B1 偵查來源 | 本次 B1 偵查：前端 `markParentFeedbackRead()`、後端 `markRead()`、`unreadCount()`、`decorateRecords()`、既有 `ParentLearningRecordFeedbackTest`。 |

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 家長回饋已讀狀態修復與評量表回饋定位優化 |
| 日期 | 2026-04-26 |
| 狀態 | Draft |
| 目標角色 | 老師、主任、super_admin |
| 關聯 Bug | 家長回饋讀完後刷新又變未讀；家長回饋難以定位 |

## 2. 業務背景與影響

家長回饋是家長與老師/主任溝通的重要入口。未讀狀態若反覆回來，會讓 staff 不信任 badge；若列表缺少聚焦回饋的入口，老師/主任需要在多個學生、科目、狀態 tab 中逐筆找回饋，降低回覆效率。

修復後預期行為：
- 已讀狀態持久保存，刷新後不反彈。
- 評量頁可快速聚焦「有家長回饋」與「未讀回饋」。
- 現有多校區與角色隔離不變。

## 3. 範圍

In Scope：
- 修正 mark-read 不應更新 `updated_at`。
- 前端 mark-read 應檢查 API 成功後才樂觀清除 UI。
- 評量頁新增回饋篩選：全部 / 有家長回饋 / 未讀回饋。
- 若目前 tab 隱藏了有回饋記錄，提供可點擊提示切到全部或回饋篩選。
- 補 regression test。

Out of Scope：
- 不改家長端送出回饋流程。
- 不新增資料表或 migration。
- 不改 LINE/通知中心架構。
- 不做回覆家長的雙向訊息系統。

## 4. RACI

| 角色 | 代表 | R/A/C/I |
|---|---|---|
| AI Agent（後端修復） | `[DEV]` Agent | R |
| AI Agent（前端修復） | `[DEV]` Agent | R |
| AI Agent（測試） | `[TEST]` Agent | R |
| AI Agent（審查） | `[REVIEW]` Agent | R |
| AI Agent（文件） | `[DOCS]` Agent | R |
| AI Agent（部署） | `[OPS]` Agent | R |
| 人類 | 使用者 | I |

## 4b. Dependencies

| 類型 | 說明 | 狀態 |
|---|---|---|
| 前置 PR | 無 |
| 外部服務 | 無 |
| 環境前提 | `learning_record_feedbacks` 表已存在 |

## 5. Acceptance Criteria

### AC-001：已讀狀態持久化
- AC-001-a：老師讀取一筆未讀家長回饋後，`GET /api/v1/me/unread-feedback-count` 對老師回傳 0。
- AC-001-b：同一筆資料重新由 `GET /api/v1/learning-records` 載入時，`parent_feedback.unread_for_teacher=false`。
- AC-001-c：mark-read 不更新該筆 feedback 的 `updated_at`。

### AC-002：主任/老師角色分離
- AC-002-a：老師讀取後只清老師 unread，不清主任 unread。
- AC-002-b：主任讀取後只清主任 unread，不清老師 unread。

### AC-003：回饋可定位
- AC-003-a：評量頁可切換「有家長回饋」並只顯示 `parent_feedback` 存在的評量。
- AC-003-b：評量頁可切換「未讀回饋」並只顯示當前角色未讀的回饋。
- AC-003-c：未讀回饋列表保留橘色高亮，已讀回饋保留一般 chip。

## 6. 功能需求 FR

- FR-001：系統 mark-read 時只更新角色對應的 `last_read_by_*_at`，不得更新 `updated_at`。
- FR-002：前端 mark-read API 非 2xx 時，不得把 UI 樂觀改成已讀。
- FR-003：評量頁應提供回饋篩選狀態：全部、有家長回饋、未讀回饋。
- FR-004：回饋篩選應與既有角色 tab、學生/科目篩選、日期窗口並存。
- FR-005：未讀 badge 更新後應觸發 `refreshUnreadNotifications()`。

## 7. 非功能需求 NFR

- NFR-001：不新增後端查詢 endpoint。
- NFR-002：不新增 N+1；前端篩選使用已載入的 `parent_feedback` 欄位。
- NFR-003：不改資料保存格式，不需要 migration。

## 8. 技術方向

- 後端：調整 `LearningRecordFeedbackController::markRead()`，用不觸發 timestamps 的方式更新已讀欄位。
- 後端測試：擴充 `ParentLearningRecordFeedbackTest`，驗證 mark-read 後 `updated_at` 不變，且重新查詢 unread flag 為 false。
- 前端：在 `LearningRecordsPage` 現有 tabs 附近增加輕量回饋 filter chip；在 `filteredRecords` 中套用 `parent_feedback` 與 `parentFeedbackUnread()` 條件。
- 前端：`markParentFeedbackRead()` 檢查 `res.ok` 後才改本地 flag。

## 8b. Decision Log

| 日期 | 決策 | 考慮過的替代方案 | 選擇理由 |
|---|---|---|---|
| 2026-04-26 | mark-read 不碰 `updated_at` | 改 unread 比較條件 / 新增 `parent_updated_at` 欄位 | 無 migration、最小修復，符合目前 schema。 |
| 2026-04-26 | 前端用 filter chip 聚焦回饋 | 新增獨立家長回饋頁 / 彈窗列表 | 小範圍、低風險，使用者仍在評量上下文中處理。 |
| 2026-04-26 | 保持老師/主任已讀分離 | 一人讀取即全角色已讀 | 現有 schema 明確分成 teacher/director 兩個 read 欄位，保留責任分工。 |

## 9. 資安與存取控制

**SEC 審查結果（Phase 4）**
- 觸發原因：涉及角色可見性與學生/家長回饋內容。
- STRIDE 掃描：S-沿用 Bearer token；T-mark-read 只允許自己角色可讀範圍；R-沿用資料表時間欄位；I-不新增跨校資料；D-無新 endpoint；E-不新增權限。
- 發現問題：無新增 HIGH 風險。
- 處置方式：測試覆蓋 teacher/director scope 與 branch guard。

## 10. QA 驗收

Happy Path：
- 老師看到未讀回饋，點開預覽或評量 detail 後變已讀。
- 刷新頁面後仍維持已讀。
- 切到「未讀回饋」後該筆不再顯示。

Edge：
- 後端 mark-read 回 403/500 時，前端不清除 unread。
- 主任讀取不影響老師未讀。
- 老師讀取不影響主任未讀。

Revert-proof 驗證：
- 還原後端 mark-read 修復後，新增測試中「updated_at 不變 / 重新查詢 unread=false」至少一項失敗。

## 11. 上線與維運

部署步驟：
- feature branch → PR → CI 全綠 → merge → deploy workflow。
- 無 migration。
- 前端改動由 deploy workflow build/copy。

Observability：

| 監控項目 | 指標 / log 關鍵字 | 告警閾值 | 負責 Agent |
|---|---|---|---|
| CI | PHPUnit + Vite | failure | `[TEST]` |
| Health check | `/api/v1/health` | 非 200 | `[OPS]` |
| 未讀數 | `/api/v1/me/unread-feedback-count` | 已讀後仍 > 0 | `[TEST]` |

回滾：
- `git revert` 本 PR；無 migration rollback。

## 12. 優先級

- P0 `[DEV]`：修 mark-read timestamps bug。
- P1 `[DEV]`：新增回饋篩選 chip。
- P1 `[TEST]`：補 regression test。
- P1 `[REVIEW]`：資安與多校區隔離審查。
- P2 `[DOCS]`：CHANGELOG，若確認為防再犯項目則補 `AI_REGRESSION_LESSONS.md`。

## 13. 風險 / 假設 / 開放問題

業界參考：
- PatternFly notification badge：unread badge 必須能被清除，否則會失去信任。
- Badge UX 文章：badge 應回答「是否有新內容」與「何時消失」，避免永遠存在或閃爍。
- Reviews / feedback UX：提供搜尋、篩選、排序是大量回饋可用性的關鍵。

| 風險 | 等級 | 業界標準解法 | 本專案採行方式 |
|---|---|---|---|
| badge 永遠不清，使用者不信任 | 中 | 清楚定義 read event | 開預覽/detail 成功 mark-read 後才清除。 |
| 回饋藏在大量評量中 | 中 | filter/sort/highlight | 新增有回饋/未讀回饋 filter chip。 |
| 只前端樂觀清除造成刷新反彈 | 中 | API 成功後更新 UI | 檢查 `res.ok`。 |

假設：
- 現有 `updated_at` 代表家長最後提交/更新回饋時間；mark-read 不應改變它。

開放問題：
- 無。

## 14. Definition of Done

- [ ] Mark-read 不更新 `updated_at`：驗證方式：PHPUnit 斷言 `updated_at` 與讀取前相同。
- [ ] 讀取後刷新仍已讀：驗證方式：PHPUnit 重新打 `GET /api/v1/learning-records`，對應 `unread_for_*` 為 false。
- [ ] 前端 build 成功：驗證方式：`cd frontend && npm run build` 回傳 success。
- [ ] 無 migration：驗證方式：`git diff --name-only` 不包含 `backend/database/migrations/`。
- [ ] CHANGELOG 已更新：驗證方式：`git diff docs/CHANGELOG.md` 含本次條目。
