# PRD：學習評量回饋／回覆接推播（觸達率提升）

> Tier：**T3（PII + 對家長 LINE 推播 + 防騷擾節流）** → 需使用者批准後才進 DEV。
> 來源：`docs/reviews/PRODUCT_GAP_REVIEW_2026-06.md` §區1（本區最大缺口：回饋/回覆只有 App 內紅點，無推播）。
> 設計原則：**重用既有兩條推播 lane，不新建 infra**；先做最小可行（in-app 必開、LINE 視綁定）。

---

## 1. 文件資訊
- 作者：AI（ARCH/PLAN）｜日期：2026-06-01｜狀態：待批准
- 影響模組：`learning_record_feedbacks` / `_replies`、`Notification`/`NotificationSyncService`、`NotificationLineDispatcher`、`StudentLineBinding`、`LineWebhookController` push、家長 ParentPortal 設定
- 相關文件（引用，不重複）：`AI_REGRESSION_LESSONS.md` §R19（三方未讀語意）、§R(2026-05-31)（端點必須 role+require_campus）；`SYSTEM_TECH_GUIDE.md` §11.5–11.7；`DIRECTOR_PAYMENT_ALERT_RULES.md`（推播 PII/節奏先例）

## 2. 目標 / KPI
- **目標**：把「家長提交回饋 / 雙向回覆」事件即時送達對方，降低靠紅點被動發現的延遲。
- **KPI**：
  - 回覆中位回應時間下降（對照 TD-057 待補的 reply-rate 指標一起看）
  - 推播送達後 24h 內已讀率
  - **護欄 KPI**：家長封鎖/退訂率 < 2%、單一家長單事件不重複推播（dedupe 命中率）

## 3. 範圍（In / Out）
**In（最小可行）**
- 4 個事件接推播（皆目前 0 推播）：
  1. 家長提交回饋（`parentUpsert`）→ 通知授課老師 + 主任
  2. 家長追加回覆（`parentReply`）→ 通知老師 + 主任
  3. 老師/主任回覆家長（`staffReply`）→ 通知家長
  4. 老師送出評量 / 主任核准（已有 staff in-app）→ **加**家長端通知「老師已填寫本週學習回饋」
- 通道：**in-app 一律建立**；**LINE 視綁定狀態**（家長有 `StudentLineBinding`、staff 有 `Teacher.LineID`）才加推。

**Out（本期不做）**
- Telegram 對外推播（目前只有 inbound binding，無 outbound service → 屬 net-new infra）
- 家長端細緻通知偏好中心（先用單一「接收學習回饋通知」開關）
- System B（campus suggestion box `ParentFeedback`）推播

## 4. RACI / 4b. Dependencies
- R：DEV（全端）｜A：使用者（批准 + 推播節奏政策）｜C：SEC（PII/STRIDE）、LEGAL（個資告知）｜I：OPS
- 依賴：家長 LINE 綁定率（TD-013，目前低 → LINE 觸達有限，in-app 為保底）；`Campus.messaging_channel_token` 已設定

## 5. User Stories + AC（節錄）
- US1 家長提交回饋 → 老師 5 分鐘內收到 in-app（有綁定再加 LINE）。**AC**：同一筆回饋只通知一次；老師已讀後紅點清除。
- US3 老師回覆家長 → 家長收到「老師回覆了您的回饋」+ 深連結到該筆學習紀錄。**AC**：未綁定 LINE 的家長僅 ParentPortal 內提示；不外洩其他學生資訊。
- US4 家長可在 ParentPortal 關閉「學習回饋通知」。**AC**：關閉後不再推 LINE（in-app 仍保留紀錄）。

## 5b. UI/UX
- Staff：沿用既有通知鈴鐺（`learning_review`/新增 `lr_feedback` Type），不新增頁面。
- 家長 LINE：Flex 卡片（標題＋「查看」按鈕深連結），文案白話、**不含**欄位名/班級內部碼，只露學生名與「學習回饋」字樣。
- 家長設定：ParentPortal 既有設定區加一個 toggle「接收學習回饋通知」。

## 6. FR
- FR1：在 4 個 controller 動作後，建立對應 `Notification` 列（staff 向）/ 或呼叫 parent LINE push（家長向），**沿用現有 pattern**：
  - staff 向：`Notification::create([... SourceType='LearningRecordFeedback', SourceKey="lrfb:{campus}:{lr_id}:{event}" ...])` → observer 自動 fan-out（in-app + staff LINE if high）
  - 家長向：`StudentLineBinding` 解析 → `Campus.messaging_channel_token` → `sendFlexMessage`（鏡像 `SendTuitionReminders`）
- FR2：**Dedupe/節流**（dispatcher 目前無 per-send dedupe）：以 `idempotency_key`（事件型別+lr_id+actor+rounded-time）擋重複；同一 (recipient,event) 在 X 分鐘窗只推一次。沿用 XP `idempotency_key` unique 欄位 pattern。
- FR3：靜音時段沿用 dispatcher 既有 quiet-hours；家長 opt-out 旗標檢查。
- FR4：所有新端點/查詢在 `role:`+`require_campus` 內，家長動作經 hashed token → `ParentSession` 並逐列 ownership（`student_id===session.StudentID`、僅 `approved`）。

## 7. NFR
- 推播為 best-effort，**不可阻塞**回饋/回覆主流程（失敗只記 log，不回 500）。
- 不得 N+1：批次解析 binding；單事件單次 push。

## 8. 技術方向（禁 code）
- 兩條 lane 並存（已存在）：**staff = Notification observer**；**parent = StudentLineBinding + campus token**。回覆方向決定走哪條。
- dedupe 用新 `feedback_push_log`（或重用 notifications SourceKey upsert + 新增 parent push idempotency 表）。
- 家長 opt-out 旗標：`student_line_bindings` 或 ParentSession 關聯加 `notify_learning_feedback`（預設 on）。

## 8b. Decision Log
- D1：Telegram 不納入（無 outbound infra，net-new；ROI 低於先補 LINE+in-app）。
- D2：家長未綁定 LINE 時不阻擋，靠 in-app/ParentPortal 紅點保底（綁定率低，TD-013）。
- D3：MVP 不做完整偏好中心，先單一 toggle（避免過度工程）。

## 9. 資安（STRIDE-lite，T3 必填）
- **S（偽冒）**：家長端一律 hashed token→ParentSession；staff 端 role middleware。新端點不得無認證（記取 §R(2026-05-31) System B 無認證事故）。
- **T（竄改）**：推播內容由後端組裝，不接受前端傳入文案。
- **R（否認）**：push log 記 actor/event/time。
- **I（洩漏）⚠️最高風險**：跨 campus 同 `line_user_id` ≠ 同家庭（§R cross-family）；推播內容**只**含該學生名 + 學習回饋字樣，**不**含其他學生、不含內部碼/欄位名（對齊 user-facing-communication 規則）；LINE ID 在 staff UI 一律 masked。
- **D（阻斷）**：push 失敗不影響主流程；route throttle 已有 `throttle:20,1`。
- **E（提權）**：家長只能對自己學生的 `approved` 紀錄觸發，逐列 ownership 檢查。
- **個資（LEGAL）**：對家長推播屬既有 LINE 管道既有用途（評量/繳費已在推），非新類別 PII 外傳；仍建議 ParentPortal 明示「學習回饋通知」可關閉（蒐集最小化 + 退出權）。

## 10. QA 驗收
- 4 事件各：未綁定（只 in-app）/ 已綁定（in-app + LINE）兩條路徑
- dedupe：連點兩次回覆只推一次
- opt-out：關閉後無 LINE、in-app 仍在
- 跨 campus 隔離：A 校 binding 不會收到 B 校事件
- 主流程：push service 故障時回饋/回覆仍 200

## 11. 上線維運
- 後端 only（家長 toggle 若做要前端）→ 視 diff 決定是否 deploy 前端。
- Rollback：feature flag（perfflags `feedback_push_enabled`）一鍵關。

## 12. 優先級
- P1（review 跨區最高 CP），但受 LINE 綁定率限制 → 真正觸達提升需搭配 TD-013（之後另案）。

## 13. 風險（先 WebSearch 業界做法後補）
- 推播疲勞 / 家長封鎖官方帳號 → 用 dedupe + quiet-hours + opt-out 緩解（業界：Intercom/Seesaw 皆有 digest + 偏好）。
- 綁定率低 → MVP 觸達有限，需誠實對 KPI 設期望值。

## 14. DoD（AI 可驗證）
- [ ] 4 事件接推（in-app 必、LINE 視綁定），feature flag 可關
- [ ] dedupe/idempotency 測試綠
- [ ] 跨 campus 隔離測試綠
- [ ] push 故障不阻斷主流程測試綠
- [ ] 家長 opt-out 生效測試綠
- [ ] CHANGELOG + AI_REGRESSION（若補 PII 規則）+ TECH_DEBT（TD-013 關聯、TD-057 KPI）更新
- [ ] CI 綠 → merge → deploy → health

---

### 需使用者決策的 3 個政策問題
1. **推播節奏**：家長追加回覆是否「每則都推」給 staff，或「同一 thread X 分鐘內合併一則」？（防疲勞）
2. **家長端 opt-out**：MVP 是否需要家長可關閉（建議要，個資退出權）？預設 on？
3. **範圍**：是否同意 Telegram 本期不做（無 outbound infra）、先 LINE + in-app？
