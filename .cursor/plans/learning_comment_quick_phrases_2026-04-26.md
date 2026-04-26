# [PLAN] Learning Record Parent-Safe Comment Quick Phrases

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 學習評量「學習進度與家長溝通」快捷片語優化 |
| 日期 | 2026-04-26 |
| 版本 | v1 |
| 狀態 | Draft |
| 目標角色 | 老師、主任、家長 |

## 2. 目標與業務背景

老師在編輯學習評量時，目前「學習進度與家長溝通」只有 3 個快捷片語，無法涵蓋常見情境。目標是讓老師更快寫出家長看得懂、語氣合適、具體可行的回饋，同時避免畫面塞滿按鈕。

KPI：
- 老師可從 10 個高頻家長溝通片語中選擇合適內容。
- 預設畫面最多顯示 6 個片語按鈕，額外 4 個透過「顯示更多」展開。
- 所有新增片語皆為家長可見、正向或建設性語氣。

## 3. 範圍

In Scope：
- 調整 `LearningRecordsPage` 的 `Comment` 快捷片語。
- 若片語數量超過 6 個，採分類或「更多」漸進揭露設計。
- 保持文字可自由編輯，快捷片語只做輔助。

Out of Scope：
- 不新增後端 API、資料表、權限規則。
- 不改家長端顯示格式。
- 不導入 AI 自動生成評語。
- 不新增老師自訂片語管理後台。

## 4. RACI

| 角色 | 代表 | R/A/C/I |
|---|---|---|
| AI Agent（實作） | `[FEATURE]` Agent | R |
| AI Agent（測試） | `[TEST]` Agent | R |
| AI Agent（審查） | `[REVIEW]` Agent | R |
| AI Agent（文件） | `[DOCS]` Agent | R |
| AI Agent（部署） | `[OPS]` Agent | R |
| 人類 | 使用者 | I |

## 4b. Dependencies

| 類型 | 說明 | 狀態 |
|---|---|---|
| 前置 PR | 無 | 已確認 |
| 外部服務 | 無 | 已確認 |
| 環境前提 | 前端既有 `templatePhrases.Comment` | 已存在 |

## 5. User Stories + AC

US-001：As a 老師，我想快速選擇常用家長溝通片語 so that 我可以更快完成評量。
AC：編輯評量時可看到比現有 3 個更多的家長溝通選項，點擊後插入文字到 `Comment`。

US-002：As a 老師，我想片語不要佔滿畫面 so that 我仍然能專注輸入評量內容。
AC：預設顯示不超過 6 個片語，額外選項需透過分類或展開才出現。

US-003：As a 家長，我想看到具體且友善的建議 so that 我知道在家如何支持孩子。
AC：新增片語不得使用「不良」「退步」「不用心」等負面標籤，需包含可行動方向。

## 5b. UI/UX 精緻化

| 面向 | 規格 |
|---|---|
| 版面層次 | 保留 textarea 為主，快捷片語作為次要 chip；預設最多 2 行。 |
| 色彩一致性 | 沿用既有 `.lr-phrase-btn` 淺灰樣式；不新增高飽和警示色。 |
| 互動回饋 | 點擊片語沿用現有插入行為；hover 保持既有藍色回饋。 |
| 空狀態 | 不適用，片語為靜態設定。 |
| 載入狀態 | 不適用，無非同步請求。 |
| 防呆設計 | 片語只插入文字，不覆蓋老師已輸入內容。 |
| 響應式 | 小螢幕仍可換行；每個按鈕觸控目標需接近 44px 高度或保留足夠 padding。 |
| 無障礙 | 按鈕文字即為可讀標籤，鍵盤 Tab 可操作。 |

## 6. 功能需求 FR

- FR-001：系統應提供 10 個家長溝通快捷片語。
- FR-002：系統應優先提供「優勢肯定、持續努力、需要練習、專注提醒、在家支持、溝通邀請、理解確認、作業訂正、考前準備、主動提問」十類高頻情境。
- FR-003：系統應預設只顯示 6 個片語，額外 4 個需透過「顯示更多」展開，避免所有按鈕同時展開。
- FR-004：所有片語點擊後應插入到 `Comment` 欄位，且不清除既有內容。
- FR-005：所有新增片語應適合直接出現在家長端。

## 7. 非功能需求 NFR

- NFR-001：不得新增 API 呼叫，畫面載入時間不應變慢。
- NFR-002：不得改變既有評量儲存 payload。
- NFR-003：前端 bundle 增量應可忽略，不引入新套件。

## 8. 技術方向

- 只調整前端學習評量頁的快捷片語資料與必要的 UI 排列。
- 優先採靜態片語陣列，因為本次需求是固定常用語，不需要後端設定。
- 不改家長端，因為家長端已直接顯示 `Comment`，本次重點是輸入端文案品質。

## 8b. Decision Log

| 日期 | 決策 | 考慮過的替代方案 | 選擇理由 |
|---|---|---|---|
| 2026-04-26 | 先做固定精選片語 | 老師自訂片語後台 / AI 生成 | 範圍小、風險低、符合現有架構。 |
| 2026-04-26 | 提供 10 個片語但預設只顯示 6 個 | 一次顯示 10 個按鈕 / 只提供 6 個 | 回應老師 10 個選項需求，同時避免版面混亂。 |
| 2026-04-26 | 只用家長可見語氣 | 老師內部備註語氣 | `Comment` 會出現在家長端，需 parent-safe。 |

## 9. 資安與存取控制

- Role：沿用既有評量編輯權限，不新增入口。
- PII：不新增學生、家長、電話等個資欄位。
- STRIDE 快評：
  - Spoofing：無新身份流程。
  - Tampering：沿用既有表單儲存。
  - Repudiation：沿用既有評量紀錄。
  - Information Disclosure：新增文案不得暗示診斷、家庭狀況或敏感推論。
  - Denial of Service：無新增 API。
  - Elevation of Privilege：無權限變更。

## 10. QA 驗收

Happy Path：
- 老師開啟可編輯評量，預設看到 6 個片語與「顯示更多 4 個」按鈕。
- 老師點擊「顯示更多 4 個」後，可看到全部 10 個片語，並可再收合。
- 點擊任一片語，文字插入 `Comment`。
- 儲存後家長端可看到同樣文字。

Edge：
- `Comment` 已有文字時，點擊片語不覆蓋原內容。
- 小螢幕下按鈕不造成水平 overflow。
- 已核准或唯讀狀態不顯示可點擊片語。

UI/UX：
- 預設片語區不超過 2 行。
- 文案語氣正向、具體、可行動。
- 鍵盤可 Tab 到片語按鈕。

## 11. 上線與維運

部署步驟：
- 前端變更需走 feature branch + PR + CI。
- PR merge 到 main 後才可 deploy，並確認 health check 與 `version.json`。

Feature Flag：
- 不使用 feature flag；本次為低風險靜態文案與輕量 UI。

Observability：

| 監控項目 | 指標 / log 關鍵字 | 告警閾值 | 負責 Agent |
|---|---|---|---|
| 前端 build | `npm run build` | build failed | `[OPS]` |
| Health check | `/api/v1/health` | 非 200 | `[OPS]` |

回滾：
- 使用 `git revert` 回復前端片語/UI commit；無 migration rollback。

## 12. 里程碑與優先級

- P0 `[FEATURE]`：新增 10 個 parent-safe 精選片語。
- P1 `[FEATURE]`：加入「顯示更多 / 收合」漸進揭露。
- P1 `[TEST]`：執行前端 build。
- P1 `[REVIEW]`：審查家長可見文案與版面。
- P2 `[DOCS]`：更新 CHANGELOG。

## 13. 風險 / 假設 / 開放問題

業界參考：
- Google Classroom Comment Bank：常用評語可儲存與重用，並提供搜尋/管理以降低重複輸入成本。
- Report card comment banks：常見做法是按類別組織，語氣保持 balanced、family-facing，並提供 next step。
- Parent-teacher communication app guidance：家長訊息應避免過度轟炸，優先清楚、可行動、具整合脈絡。

| 風險 | 等級 | 業界標準解法 | 本專案採行方式 |
|---|---|---|---|
| 選項太多導致版面混亂 | 中 | Comment bank 分類、搜尋、漸進揭露 | 預設最多 6 個，更多選項才展開。 |
| 文案太負面造成家長反感 | 中 | Balanced comments：肯定 + 具體 next step | 避免負面標籤，改成「可透過 X 改善」。 |
| 片語太罐頭 | 低 | 提供可編輯模板與具體證據欄位 | 點擊後仍可自由編輯，不鎖死內容。 |
| 老師需要個人化片語 | 低 | Comment manager / custom bank | 本期 Out of Scope，先用精選常用語驗證。 |

假設：
- 假設 `Comment` 為家長可見欄位；若程式碼檢查發現另有內部備註欄位，則回退為只新增 parent-safe 文案。
- 假設本次不需後端設定；若後續要求每位老師自訂，另開 PRD。

開放問題：
- 無；使用者已確認需要 10 個選項，但需避免版面混亂。

## 14. Definition of Done

- [ ] 快捷片語已更新：驗證方式：搜尋 `templatePhrases.Comment` 可看到 10 個 parent-safe 片語。
- [ ] 版面不擁擠：驗證方式：[REVIEW] 對照第 5b 節，預設不超過 6 個可見片語，且可展開到 10 個。
- [ ] 前端可 build：驗證方式：`cd frontend && npm run build` 回傳成功。
- [ ] 無後端變更：驗證方式：`git diff --name-only` 不包含 `backend/`。
- [ ] CHANGELOG 已更新：驗證方式：`git diff -- docs/CHANGELOG.md` 含本次一行記錄。
